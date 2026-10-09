<?php

declare(strict_types=1);

// WP2 lifecycle proof for the source callers that need the full
// application schema (events, engagement, follows, saved searches).
//
// These cases run the actual current flows (HTTP APIs, Livewire actions,
// staff attendance, moderation, registration lifecycle) against the
// standard suite database. Concurrency itself is proven on the isolated
// real drivers by Wp2RealDriverRaceTest; what is proven here is that
// every caller freezes, inherits, or reverses coherently. If any
// correction hook or atomic wrapper below were deleted, its test fails.

use AIArmada\Affiliates\Contracts\FraudRule;
use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateFraudSignal;
use AIArmada\Affiliates\Models\AffiliateLink;
use AIArmada\Affiliates\Services\FraudDetectionService;
use AIArmada\Affiliates\States\ConversionStatus;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Engagement\Models\Follow;
use AIArmada\Engagement\Models\Response;
use AIArmada\Events\Contracts\RegistrationServiceInterface;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventSession;
use AIArmada\Events\Steps\CreateEventRegistrationsStep;
use AIArmada\Inventory\Models\InventoryLocation;
use AIArmada\Membership\Enums\MemberRole;
use AIArmada\Orders\Contracts\OrderServiceInterface;
use AIArmada\Seating\Enums\SeatingMode;
use AIArmada\Ticketing\Models\TicketType;
use App\Actions\Events\PrepareEventCheckoutAction;
use App\Actions\Events\RecordEventParticipantCheckInAction;
use App\Actions\Events\RegisterEventWithShareProvenanceAction;
use App\Actions\Events\UpdateEventParticipantAttendanceAction;
use App\Actions\ShareTracking\ReverseEventParticipationOutcomesAction;
use App\Checkout\RecordCheckoutShareProvenanceStep;
use App\Enums\DawahShareOutcomeType;
use App\Enums\EventAttendanceStatus;
use App\Enums\ShareEffectStatus;
use App\Livewire\Pages\Dashboard\Events\OfflineAdmissions;
use App\Livewire\Pages\Institutions\Show;
use App\Models\Event;
use App\Models\EventCheckin;
use App\Models\EventSubmission;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\Registration;
use App\Models\SavedSearch;
use App\Models\Series;
use App\Models\ShareEffectReceipt;
use App\Models\ShareOutcomeCorrection;
use App\Models\ShareSourceAttributionReceipt;
use App\Models\User;
use App\Services\ModerationService;
use App\Services\ShareTrackingService;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use Database\Seeders\AIArmada\EventRoleSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

beforeEach(function (): void {
    $this->seed(EventRoleSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(PermissionSeeder::class);

    $this->sharer = User::factory()->create();
});

function wp2lcLink(User $sharer, string $url): AffiliateLink
{
    app(ShareTrackingService::class)->createOrReuseLink($sharer, $url, 'Majlis', 'web');

    $affiliate = Affiliate::query()
        ->where('owner_type', $sharer->getMorphClass())
        ->where('owner_id', $sharer->getKey())
        ->firstOrFail();

    return AffiliateLink::query()->where('affiliate_id', $affiliate->getKey())->firstOrFail();
}

function wp2lcLandingCookie(TestCase $testCase, string $url): string
{
    $response = $testCase->get($url);
    $response->assertOk();

    $cookie = $response->getCookie((string) config('dawah-share.cookie.name'));

    expect($cookie)->not->toBeNull();

    return (string) $cookie?->getValue();
}

function wp2lcConversionStatus(AffiliateConversion $conversion): string
{
    $status = $conversion->status;

    return $status instanceof ConversionStatus
        ? (string) $status->getValue()
        : (string) $status;
}

function wp2lcRegistrationEvent(): Event
{
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDay(),
    ]);

    $event->accessPolicy()->updateOrCreate([], [
        'registration_required' => true,
        'capacity' => 50,
        'opens_at' => now()->subDay(),
        'closes_at' => now()->addDay(),
    ]);

    return $event;
}

/**
 * @return array{0: Event, 1: TicketType}
 */
function wp2lcPaidEvent(): array
{
    $organizer = User::factory()->create();
    $event = Event::factory()->create([
        'title' => 'Paid Checkout Event',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHours(2),
        'pricing_mode' => 'paid',
        'registration_mode' => 'required',
        'created_by_type' => $organizer->getMorphClass(),
        'created_by_id' => $organizer->getKey(),
    ]);
    addTestMember($event, $organizer, MemberRole::Owner);

    $event->accessPolicy()->delete();
    $event->accessPolicy()->create([
        'registration_required' => true,
        'capacity' => 100,
        'walk_in_allowed' => false,
        'opens_at' => now()->subDay(),
        'closes_at' => now()->addDay(),
    ]);

    $ticket = $event->ticketTypes()->create([
        'name' => 'General admission',
        'code' => 'GENERAL-WP2-'.Str::upper(Str::random(6)),
        'access_type' => 'general',
        'seating_mode' => SeatingMode::None,
        'price' => 3500,
        'currency' => 'MYR',
        'status' => 'active',
        'visibility' => 'public',
    ]);
    $ticket->inventoryLevels()->create([
        'location_id' => InventoryLocation::getOrCreateDefault()->getKey(),
        'quantity_on_hand' => 10,
    ]);

    return [$event, $ticket];
}

/**
 * @return array<string, mixed>
 */
function wp2lcCheckoutParticipant(string $name, bool $isPurchaser, ?string $email = null): array
{
    $participant = [
        'name' => $name,
        'is_primary' => $isPurchaser,
        'is_purchaser' => $isPurchaser,
        'metadata' => ['event_checkout' => ['agreement' => ['version' => 'event-participation-v1']]],
    ];

    if ($email !== null) {
        $participant['email'] = $email;
    }

    return $participant;
}

final class Wp2LifecycleThrowingRule implements FraudRule
{
    public function ruleCode(): string
    {
        return 'WP2_LIFECYCLE_THROW';
    }

    public function analyzeClick(Affiliate $affiliate, Request $request, array $context): ?AffiliateFraudSignal
    {
        return null;
    }

    public function analyzeConversion(AffiliateConversion $conversion, array $context): ?AffiliateFraudSignal
    {
        throw new RuntimeException('wp2 lifecycle injected failure');
    }
}

it('records self check-in and its outcome in one transaction', function (): void {
    $actor = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now('Asia/Kuala_Lumpur')->addHour()->utc(),
        'timezone' => 'Asia/Kuala_Lumpur',
    ]);
    $event->accessPolicy()->delete();

    $link = wp2lcLink($this->sharer, route('events.show', $event));
    $cookie = wp2lcLandingCookie($this, route('events.show', $event).'?share='.$link->custom_slug);

    Sanctum::actingAs($actor);

    $this->withCredentials()
        ->withCookie((string) config('dawah-share.cookie.name'), $cookie)
        ->postJson(route('api.events.check-ins.store', $event))
        ->assertCreated();

    $checkin = EventCheckin::query()
        ->where('event_id', $event->getKey())
        ->where('attendee_id', $actor->getKey())
        ->firstOrFail();

    $receipt = ShareSourceAttributionReceipt::query()
        ->where('business_key', ShareOutcomeBusinessKey::eventCheckin((string) $checkin->getKey()))
        ->firstOrFail();

    expect($receipt->isAttributed())->toBeTrue()
        ->and(AffiliateConversion::query()->count())->toBe(1);

    // A duplicate check-in replays the committed decision and writes
    // nothing more.
    $this->withCredentials()
        ->withCookie((string) config('dawah-share.cookie.name'), $cookie)
        ->postJson(route('api.events.check-ins.store', $event))
        ->assertOk();

    expect(EventCheckin::query()->where('event_id', $event->getKey())->count())->toBe(1)
        ->and(ShareSourceAttributionReceipt::query()->count())->toBe(1)
        ->and(AffiliateConversion::query()->count())->toBe(1);
});

it('rolls back self check-in together with its outcome on failure', function (): void {
    $actor = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now('Asia/Kuala_Lumpur')->addHour()->utc(),
        'timezone' => 'Asia/Kuala_Lumpur',
    ]);
    $event->accessPolicy()->delete();

    $link = wp2lcLink($this->sharer, route('events.show', $event));
    $cookie = wp2lcLandingCookie($this, route('events.show', $event).'?share='.$link->custom_slug);

    app()->tag([Wp2LifecycleThrowingRule::class], 'affiliates.fraud_rule');
    app()->forgetInstance(FraudDetectionService::class);

    Sanctum::actingAs($actor);

    $this->withCredentials()
        ->withCookie((string) config('dawah-share.cookie.name'), $cookie)
        ->postJson(route('api.events.check-ins.store', $event))
        ->assertServerError();

    expect(EventCheckin::query()->where('event_id', $event->getKey())->count())->toBe(0)
        ->and(ShareSourceAttributionReceipt::query()->count())->toBe(0)
        ->and(AffiliateConversion::query()->count())->toBe(0);
});

it('inherits registration provenance for staff check-in and reverses on attendance correction', function (): void {
    $event = wp2lcRegistrationEvent();
    $link = wp2lcLink($this->sharer, route('events.show', $event));
    $cookie = wp2lcLandingCookie($this, route('events.show', $event).'?share='.$link->custom_slug);

    $this->withCredentials()
        ->withCookie((string) config('dawah-share.cookie.name'), $cookie)
        ->postJson(route('api.events.registrations.store', $event), [
            'name' => 'Staff Door Guest',
            'email' => 'staff-door-guest@example.test',
        ])
        ->assertCreated();

    $registration = Registration::query()->where('event_id', $event->getKey())->firstOrFail();
    $participant = $registration->participants()->firstOrFail();
    $staff = User::factory()->create();
    $staff->assignRole('super_admin');

    $sourceReceipt = ShareSourceAttributionReceipt::query()
        ->where('business_key', ShareOutcomeBusinessKey::eventRegistration((string) $registration->getKey()))
        ->firstOrFail();

    expect($sourceReceipt->isAttributed())->toBeTrue();

    $result = app(RecordEventParticipantCheckInAction::class)->handle($event, $participant, $staff);

    expect($result['status'])->toBe('created');

    $checkinReceipt = ShareSourceAttributionReceipt::query()
        ->where('business_key', ShareOutcomeBusinessKey::eventCheckin((string) $result['checkin']->getKey()))
        ->firstOrFail();

    expect($checkinReceipt->isAttributed())->toBeTrue()
        ->and((string) $checkinReceipt->attribution_id)->toBe((string) $sourceReceipt->attribution_id)
        ->and($checkinReceipt->provenance)->toBe(ShareSourceAttributionReceipt::PROVENANCE_REGISTRATION);

    $checkinKey = ShareOutcomeBusinessKey::eventCheckin((string) $result['checkin']->getKey());

    // Correcting the attendance to did-not-attend reverses the check-in
    // outcome in the same transaction.
    app(UpdateEventParticipantAttendanceAction::class)->handle(
        $event,
        $participant,
        EventAttendanceStatus::DidNotAttend,
        $staff,
    );

    expect(wp2lcConversionStatus(AffiliateConversion::query()->where('idempotency_key', hash('sha256', $checkinKey))->firstOrFail()))->toBe('reversed')
        ->and(ShareOutcomeCorrection::query()->where('business_key', $checkinKey)->count())->toBe(1)
        ->and(ShareEffectReceipt::query()->where('source_id', $checkinKey)->firstOrFail()->status)->toBe(ShareEffectStatus::Cancelled);

    // Correcting back to attended records the source truth but never
    // reopens the reversed fact into late credit.
    app(UpdateEventParticipantAttendanceAction::class)->handle(
        $event,
        $participant,
        EventAttendanceStatus::Attended,
        $staff,
    );

    expect(wp2lcConversionStatus(AffiliateConversion::query()->where('idempotency_key', hash('sha256', $checkinKey))->firstOrFail()))->toBe('reversed');
});

it('reverses the check-in outcome when attendance is cleared', function (): void {
    $event = wp2lcRegistrationEvent();
    $link = wp2lcLink($this->sharer, route('events.show', $event));
    $cookie = wp2lcLandingCookie($this, route('events.show', $event).'?share='.$link->custom_slug);

    $this->withCredentials()
        ->withCookie((string) config('dawah-share.cookie.name'), $cookie)
        ->postJson(route('api.events.registrations.store', $event), [
            'name' => 'Cleared Guest',
            'email' => 'cleared-guest@example.test',
        ])
        ->assertCreated();

    $registration = Registration::query()->where('event_id', $event->getKey())->firstOrFail();
    $participant = $registration->participants()->firstOrFail();
    $staff = User::factory()->create();
    $staff->assignRole('super_admin');

    $result = app(RecordEventParticipantCheckInAction::class)->handle($event, $participant, $staff);
    $checkinKey = ShareOutcomeBusinessKey::eventCheckin((string) $result['checkin']->getKey());

    app(UpdateEventParticipantAttendanceAction::class)->clear($event, $participant, $staff);

    expect(wp2lcConversionStatus(AffiliateConversion::query()->where('idempotency_key', hash('sha256', $checkinKey))->firstOrFail()))->toBe('reversed')
        ->and(ShareOutcomeCorrection::query()->where('business_key', $checkinKey)->count())->toBe(1);
});

it('reverses event-scope submissions on reject without cascading into session submissions', function (): void {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');
    $actor = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'pending',
        'visibility' => 'public',
    ]);

    $link = wp2lcLink($this->sharer, route('events.show', $event));
    $cookie = wp2lcLandingCookie($this, route('events.show', $event).'?share='.$link->custom_slug);

    $request = Request::create(route('events.show', $event), 'GET');
    $request->cookies->set((string) config('dawah-share.cookie.name'), $cookie);

    $eventSubmission = EventSubmission::factory()->create(['event_id' => $event->getKey()]);
    $sessionSubmission = EventSubmission::factory()->create([
        'event_id' => $event->getKey(),
        'event_session_id' => (string) Str::uuid(),
    ]);

    $service = app(ShareTrackingService::class);
    $eventKey = ShareOutcomeBusinessKey::eventSubmission((string) $eventSubmission->getKey());
    $sessionKey = ShareOutcomeBusinessKey::eventSubmission((string) $sessionSubmission->getKey());

    expect($service->recordOutcome(DawahShareOutcomeType::EventSubmission, $eventKey, $event, $actor, $request))->not->toBeNull()
        ->and($service->recordOutcome(DawahShareOutcomeType::EventSubmission, $sessionKey, $event, $actor, $request))->not->toBeNull();

    app(ModerationService::class)->reject($event, $moderator, 'spam', 'WP2 probe');

    expect(wp2lcConversionStatus(AffiliateConversion::query()->where('idempotency_key', hash('sha256', $eventKey))->firstOrFail()))->toBe('reversed')
        ->and(ShareOutcomeCorrection::query()->where('business_key', $eventKey)->count())->toBe(1)
        ->and(wp2lcConversionStatus(AffiliateConversion::query()->where('idempotency_key', hash('sha256', $sessionKey))->firstOrFail()))->toBe('approved')
        ->and(ShareOutcomeCorrection::query()->where('business_key', $sessionKey)->count())->toBe(0);
});

it('reverses registration outcomes on cancel and reject through the lifecycle listener', function (): void {
    $event = wp2lcRegistrationEvent();
    $link = wp2lcLink($this->sharer, route('events.show', $event));
    $cookie = wp2lcLandingCookie($this, route('events.show', $event).'?share='.$link->custom_slug);
    $cookieName = (string) config('dawah-share.cookie.name');

    $cancelId = $this->withCredentials()
        ->withCookie($cookieName, $cookie)
        ->postJson(route('api.events.registrations.store', $event), [
            'name' => 'Cancel Guest',
            'email' => 'cancel-guest@example.test',
        ])
        ->assertCreated()
        ->json('data.id');

    $cancelled = Registration::query()->whereKey($cancelId)->firstOrFail();

    // Only pending admissions can be rejected; the pending row is
    // fixture setup while the rejection hook is the real flow under
    // test.
    $rejected = Registration::factory()
        ->for($event)
        ->withPrimaryParticipant('Reject Guest', 'reject-guest@example.test')
        ->create(['status' => 'pending']);

    $attributedRequest = Request::create(route('events.show', $event), 'GET');
    $attributedRequest->cookies->set($cookieName, $cookie);

    expect(app(ShareTrackingService::class)->recordOutcome(
        DawahShareOutcomeType::EventRegistration,
        ShareOutcomeBusinessKey::eventRegistration((string) $rejected->getKey()),
        $event,
        null,
        $attributedRequest,
    ))->not->toBeNull();

    $cancelKey = ShareOutcomeBusinessKey::eventRegistration((string) $cancelled->getKey());
    $rejectKey = ShareOutcomeBusinessKey::eventRegistration((string) $rejected->getKey());

    expect(AffiliateConversion::query()->count())->toBe(2);

    $registrations = app(RegistrationServiceInterface::class);
    $registrations->cancel($cancelled, 'WP2 probe cancel');
    $registrations->reject($rejected, 'WP2 probe reject');

    expect(wp2lcConversionStatus(AffiliateConversion::query()->where('idempotency_key', hash('sha256', $cancelKey))->firstOrFail()))->toBe('reversed')
        ->and(ShareOutcomeCorrection::query()->where('business_key', $cancelKey)->value('reason'))->toBe('registration_cancelled')
        ->and(wp2lcConversionStatus(AffiliateConversion::query()->where('idempotency_key', hash('sha256', $rejectKey))->firstOrFail()))->toBe('reversed')
        ->and(ShareOutcomeCorrection::query()->where('business_key', $rejectKey)->value('reason'))->toBe('registration_rejected');
});

it('reverses the saved-search outcome on delete', function (): void {
    $visitor = User::factory()->create();
    $searchUrl = config('app.url').'/majlis?search=fiqh';
    $link = wp2lcLink($this->sharer, $searchUrl);
    $cookie = wp2lcLandingCookie($this, $searchUrl.'&share='.$link->custom_slug);

    Sanctum::actingAs($visitor);

    $created = $this->withCredentials()
        ->withCookie((string) config('dawah-share.cookie.name'), $cookie)
        ->postJson(route('api.saved-searches.store'), [
            'name' => 'Fiqh Alerts',
            'query' => 'fiqh',
            'notify' => 'daily',
        ])
        ->assertCreated()
        ->json();

    $savedSearch = SavedSearch::query()->whereKey($created['data']['id'])->firstOrFail();
    $businessKey = ShareOutcomeBusinessKey::savedSearch((string) $savedSearch->getKey());

    expect(AffiliateConversion::query()->where('idempotency_key', hash('sha256', $businessKey))->count())->toBe(1);

    $this->deleteJson(route('api.saved-searches.destroy', $savedSearch))->assertNoContent();

    expect(wp2lcConversionStatus(AffiliateConversion::query()->where('idempotency_key', hash('sha256', $businessKey))->firstOrFail()))->toBe('reversed')
        ->and(ShareOutcomeCorrection::query()->where('business_key', $businessKey)->value('reason'))->toBe('saved_search_deleted');
});

it('records and reverses web save and going flows on the event page', function (): void {
    $visitor = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(3),
    ]);

    $link = wp2lcLink($this->sharer, route('events.show', $event));
    $cookie = wp2lcLandingCookie($this, route('events.show', $event).'?share='.$link->custom_slug);
    $cookieName = (string) config('dawah-share.cookie.name');

    $page = Livewire::withCookie($cookieName, $cookie)
        ->actingAs($visitor)
        ->test('pages.events.show', ['event' => $event]);

    $page->call('toggleSave')->assertSet('isSaved', true);

    expect(AffiliateConversion::query()->where('conversion_type', DawahShareOutcomeType::EventSave->value)->count())->toBe(1);

    $page->call('toggleSave')->assertSet('isSaved', false);

    $saveConversion = AffiliateConversion::query()->where('conversion_type', DawahShareOutcomeType::EventSave->value)->firstOrFail();

    expect(wp2lcConversionStatus($saveConversion))->toBe('reversed')
        ->and(ShareOutcomeCorrection::query()->count())->toBe(1);

    $page->call('toggleGoing')->assertSet('isGoing', true);

    expect(AffiliateConversion::query()->where('conversion_type', DawahShareOutcomeType::EventGoing->value)->count())->toBe(1);

    $page->call('toggleGoing')->assertSet('isGoing', false);

    $goingConversion = AffiliateConversion::query()->where('conversion_type', DawahShareOutcomeType::EventGoing->value)->firstOrFail();

    expect(wp2lcConversionStatus($goingConversion))->toBe('reversed');
});

it('records and reverses follows across every followable type', function (string $type, array $attributes, DawahShareOutcomeType $outcomeType): void {
    $visitor = User::factory()->create();

    $record = match ($type) {
        'institution' => Institution::factory()->create($attributes),
        'person' => Person::factory()->create($attributes),
        'reference' => Reference::factory()->create($attributes),
        'series' => Series::factory()->create($attributes),
    };

    $showUrl = match ($type) {
        'institution' => route('institutions.show', $record),
        'person' => route('persons.show', $record),
        'reference' => route('references.show', $record),
        'series' => route('series.show', $record),
    };

    $link = wp2lcLink($this->sharer, $showUrl);
    $cookie = wp2lcLandingCookie($this, $showUrl.'?share='.$link->custom_slug);

    Sanctum::actingAs($visitor);

    $this->withCredentials()
        ->withCookie((string) config('dawah-share.cookie.name'), $cookie)
        ->postJson(route('api.client.follows.store', ['type' => $type, 'subject' => $record->slug]))
        ->assertCreated();

    $follow = Follow::query()
        ->where('follower_type', $visitor->getMorphClass())
        ->where('follower_id', $visitor->getKey())
        ->where('followable_type', $record->getMorphClass())
        ->where('followable_id', $record->getKey())
        ->firstOrFail();

    $businessKey = ShareOutcomeBusinessKey::follow($outcomeType, (string) $follow->getKey());

    expect(AffiliateConversion::query()->where('idempotency_key', hash('sha256', $businessKey))->count())->toBe(1);

    $this->deleteJson(route('api.client.follows.destroy', ['type' => $type, 'subject' => $record->slug]))->assertOk();

    expect(wp2lcConversionStatus(AffiliateConversion::query()->where('idempotency_key', hash('sha256', $businessKey))->firstOrFail()))->toBe('reversed')
        ->and(ShareOutcomeCorrection::query()->where('business_key', $businessKey)->value('reason'))->toBe('unfollowed');
})->with([
    'institution' => ['institution', ['status' => 'verified'], DawahShareOutcomeType::InstitutionFollow],
    'person' => ['person', ['status' => 'verified'], DawahShareOutcomeType::PersonFollow],
    'reference' => ['reference', ['status' => 'verified'], DawahShareOutcomeType::ReferenceFollow],
    'series' => ['series', ['visibility' => 'public', 'status' => 'active'], DawahShareOutcomeType::SeriesFollow],
]);

it('records and reverses web follows on the institution page', function (): void {
    $visitor = User::factory()->create();
    $institution = Institution::factory()->create(['status' => 'verified']);
    $showUrl = route('institutions.show', $institution);

    $link = wp2lcLink($this->sharer, $showUrl);
    $cookie = wp2lcLandingCookie($this, $showUrl.'?share='.$link->custom_slug);

    $page = Livewire::withCookie((string) config('dawah-share.cookie.name'), $cookie)
        ->actingAs($visitor)
        ->test(Show::class, ['institution' => $institution]);

    $page->call('toggleFollow')->assertSet('isFollowing', true);

    $follow = Follow::query()
        ->where('follower_type', $visitor->getMorphClass())
        ->where('follower_id', $visitor->getKey())
        ->where('followable_type', $institution->getMorphClass())
        ->where('followable_id', $institution->getKey())
        ->firstOrFail();

    $businessKey = ShareOutcomeBusinessKey::follow(DawahShareOutcomeType::InstitutionFollow, (string) $follow->getKey());

    expect(AffiliateConversion::query()->where('idempotency_key', hash('sha256', $businessKey))->count())->toBe(1);

    $page->call('toggleFollow')->assertSet('isFollowing', false);

    expect(wp2lcConversionStatus(AffiliateConversion::query()->where('idempotency_key', hash('sha256', $businessKey))->firstOrFail()))->toBe('reversed');
});

it('freezes staff-issued offline admissions unattributed without leaking the staff cookie', function (): void {
    $organizer = User::factory()->create();
    $event = Event::factory()->create([
        'title' => 'Offline Admission Event',
        'status' => 'approved',
        'visibility' => 'public',
        'published_at' => now()->subDay(),
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHours(2),
        'pricing_mode' => 'paid',
        'registration_mode' => 'required',
        'created_by_type' => $organizer->getMorphClass(),
        'created_by_id' => $organizer->getKey(),
    ]);
    addTestMember($event, $organizer, MemberRole::Owner);

    $event->accessPolicy()->delete();
    $event->accessPolicy()->create([
        'registration_required' => true,
        'capacity' => 100,
        'walk_in_allowed' => false,
        'opens_at' => now()->subDay(),
        'closes_at' => now()->addDay(),
    ]);

    $ticket = $event->ticketTypes()->create([
        'name' => 'General admission',
        'code' => 'GENERAL-WP2-PROBE',
        'access_type' => 'general',
        'seating_mode' => SeatingMode::None,
        'price' => 3500,
        'currency' => 'MYR',
        'status' => 'active',
        'visibility' => 'public',
    ]);
    $ticket->inventoryLevels()->create([
        'location_id' => InventoryLocation::getOrCreateDefault()->getKey(),
        'quantity_on_hand' => 3,
    ]);

    // The organizer carries a valid landing cookie of their own; it must
    // never credit the participant fact they issue at the desk.
    $link = wp2lcLink($this->sharer, route('events.show', $event));
    $cookie = wp2lcLandingCookie($this, route('events.show', $event).'?share='.$link->custom_slug);

    Livewire::withCookie((string) config('dawah-share.cookie.name'), $cookie)
        ->actingAs($organizer)
        ->test(OfflineAdmissions::class, ['event' => $event])
        ->set('ticketId', (string) $ticket->getKey())
        ->set('participant.name', 'Door Participant')
        ->set('participant.email', 'door-wp2@example.com')
        ->set('agreementAccepted', true)
        ->set('paymentState', 'confirmed')
        ->set('paymentMethod', 'cash')
        ->set('paymentReference', 'CASH-WP2')
        ->call('submit')
        ->assertHasNoErrors();

    $registration = Registration::query()->where('source', 'offline_admission')->firstOrFail();
    $receipt = ShareSourceAttributionReceipt::query()
        ->where('business_key', ShareOutcomeBusinessKey::eventRegistration((string) $registration->getKey()))
        ->firstOrFail();

    expect($receipt->isAttributed())->toBeFalse()
        ->and(AffiliateConversion::query()->count())->toBe(0);
});

it('never late-credits a going reactivation through a new cookie', function (): void {
    $actor = User::factory()->create();
    $event = Event::factory()->create([
        'status' => 'approved',
        'visibility' => 'public',
        'starts_at' => now()->addDays(3),
        'pricing_mode' => 'free',
    ]);

    // A historical going transition with no receipt: the real package row
    // was created and cancelled outside any share flow.
    $actor->respond($event, 'going');
    $actor->cancelResponse($event);

    $link = wp2lcLink($this->sharer, route('events.show', $event));
    $cookie = wp2lcLandingCookie($this, route('events.show', $event).'?share='.$link->custom_slug);

    Sanctum::actingAs($actor);

    // Reactivation reuses the row: the public status reads created, but
    // response_created is false, so no going decision may freeze even
    // though the fresh cookie is eligible.
    $this->withCredentials()
        ->withCookie((string) config('dawah-share.cookie.name'), $cookie)
        ->putJson(route('api.events.going.update', $event))
        ->assertCreated();

    expect(Response::query()->where('response_type', 'going')->count())->toBe(1)
        ->and(ShareSourceAttributionReceipt::query()->where('outcome_type', DawahShareOutcomeType::EventGoing->value)->count())->toBe(0)
        ->and(AffiliateConversion::query()->where('conversion_type', DawahShareOutcomeType::EventGoing->value)->count())->toBe(0);
});

it('freezes the paid-checkout intent decision once and never on retry', function (): void {
    config()->set('events.features.commerce.public_paid_checkout_enabled', true);

    [$event, $ticket] = wp2lcPaidEvent();
    $buyer = User::factory()->create();

    $link = wp2lcLink($this->sharer, route('events.show', $event));
    $cookie = wp2lcLandingCookie($this, route('events.show', $event).'?share='.$link->custom_slug);

    $attributedRequest = Request::create(route('events.show', $event), 'GET');
    $attributedRequest->cookies->set((string) config('dawah-share.cookie.name'), $cookie);

    $session = OwnerContext::withOwner(null, fn () => app(PrepareEventCheckoutAction::class)->handle(
        event: $event,
        ticketType: $ticket,
        quantity: 1,
        participants: [wp2lcCheckoutParticipant('Checkout Buyer', true)],
        buyer: $buyer,
        agreement: ['version' => 'event-participation-v1'],
        discountCode: null,
        idempotencyKey: 'wp2-checkout-probe',
        request: $attributedRequest,
    ));

    $provenance = data_get($session->payment_data, 'share_provenance');

    expect($provenance)->toBeArray()
        ->and($provenance['intent_id'] ?? null)->toBe((string) $session->getKey())
        ->and($provenance['attribution_id'] ?? null)->not->toBeNull()
        ->and($provenance['occurred_at'] ?? null)->not->toBeNull();

    // The idempotent retry reuses the durable session untouched: a later
    // unattributed request can never rewrite the frozen decision.
    $replay = OwnerContext::withOwner(null, fn () => app(PrepareEventCheckoutAction::class)->handle(
        event: $event,
        ticketType: $ticket,
        quantity: 1,
        participants: [wp2lcCheckoutParticipant('Checkout Buyer', true)],
        buyer: $buyer,
        agreement: ['version' => 'event-participation-v1'],
        discountCode: null,
        idempotencyKey: 'wp2-checkout-probe',
        request: new Request,
    ));

    expect((string) $replay->getKey())->toBe((string) $session->getKey())
        ->and(data_get($replay->fresh()->payment_data, 'share_provenance'))->toBe($provenance);
});

it('inherits the frozen checkout decision for paid order registrations', function (): void {
    config()->set('events.features.commerce.public_paid_checkout_enabled', true);

    [$event, $ticket] = wp2lcPaidEvent();
    $buyer = User::factory()->create();
    $guestEmail = 'checkout-guest@example.test';

    $link = wp2lcLink($this->sharer, route('events.show', $event));
    $cookie = wp2lcLandingCookie($this, route('events.show', $event).'?share='.$link->custom_slug);

    $attributedRequest = Request::create(route('events.show', $event), 'GET');
    $attributedRequest->cookies->set((string) config('dawah-share.cookie.name'), $cookie);

    $participants = [
        array_merge(wp2lcCheckoutParticipant('Checkout Buyer', true), [
            'participant_type' => $buyer->getMorphClass(),
            'participant_id' => (string) $buyer->getKey(),
        ]),
        wp2lcCheckoutParticipant('Checkout Guest', false, $guestEmail),
    ];

    $session = OwnerContext::withOwner(null, fn () => app(PrepareEventCheckoutAction::class)->handle(
        event: $event,
        ticketType: $ticket,
        quantity: 2,
        participants: $participants,
        buyer: $buyer,
        agreement: ['version' => 'event-participation-v1'],
        discountCode: null,
        idempotencyKey: 'wp2-checkout-inherit',
        request: $attributedRequest,
    ));

    $persistedAttributionId = data_get($session->payment_data, 'share_provenance.attribution_id');
    $persistedOccurredAt = data_get($session->payment_data, 'share_provenance.occurred_at');

    expect($persistedAttributionId)->not->toBeNull();

    // The paid order the create_order step would build after payment
    // confirmation. The session linkage mirrors that step's order_id write.
    $order = OwnerContext::withOwner(null, fn () => app(OrderServiceInterface::class)->createOrder(
        orderData: [
            'customer_id' => (string) $buyer->getKey(),
            'customer_type' => $buyer->getMorphClass(),
            'subtotal' => 7000,
            'discount_total' => 0,
            'shipping_total' => 0,
            'tax_total' => 0,
            'grand_total' => 7000,
            'currency' => 'MYR',
            'metadata' => [
                'event_id' => (string) $event->getKey(),
                'event_fulfillment' => 'event_registration',
            ],
        ],
        items: [[
            'purchasable_id' => $ticket->getKey(),
            'purchasable_type' => $ticket->getMorphClass(),
            'name' => (string) $ticket->name,
            'sku' => (string) $ticket->code,
            'quantity' => 2,
            'unit_price' => 3500,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'currency' => 'MYR',
        ]],
        intakeSource: 'checkout',
        intakeId: (string) $session->getKey(),
    ));

    $session->forceFill(['order_id' => $order->getKey()])->save();

    // The real post-payment pipeline: registrations first, then the share
    // provenance step inherits the persisted intent decision for each
    // accepted registration key exactly once.
    $created = OwnerContext::withOwner(null, fn () => app(CreateEventRegistrationsStep::class)->handle($session->fresh()));

    expect($created->isSuccessful())->toBeTrue();

    $provenance = OwnerContext::withOwner(null, fn () => app(RecordCheckoutShareProvenanceStep::class)->handle($session->fresh()));

    expect($provenance->isSuccessful())->toBeTrue();

    $registrations = Registration::query()->where('event_id', $event->getKey())->get();

    expect($registrations)->toHaveCount(2)
        ->and(ShareSourceAttributionReceipt::query()->count())->toBe(2)
        ->and(AffiliateConversion::query()->count())->toBe(2);

    // The real step resolves participants from the checkout cart snapshot,
    // not the generic customer fallback.
    expect($registrations->map(fn (Registration $registration): ?string => $registration->participants()->first()?->name)->sort()->values()->all())
        ->toBe(['Checkout Buyer', 'Checkout Guest']);

    foreach ($registrations as $registration) {
        $receipt = ShareSourceAttributionReceipt::query()
            ->where('business_key', ShareOutcomeBusinessKey::eventRegistration((string) $registration->getKey()))
            ->firstOrFail();

        expect($receipt->isAttributed())->toBeTrue()
            ->and((string) $receipt->attribution_id)->toBe((string) $persistedAttributionId)
            ->and($receipt->provenance)->toBe(ShareSourceAttributionReceipt::PROVENANCE_CHECKOUT)
            ->and($receipt->occurred_at->toIso8601String())->toBe($persistedOccurredAt);
    }

    // Participant, purchaser, and guest stay distinct: the linked
    // participant carries the buyer actor, the guest carries none, and
    // both record the same server-side purchaser.
    $conversionFor = fn (Registration $registration): AffiliateConversion => AffiliateConversion::query()
        ->where('idempotency_key', hash('sha256', ShareOutcomeBusinessKey::eventRegistration((string) $registration->getKey())))
        ->firstOrFail();

    $byName = $registrations->mapWithKeys(function (Registration $registration): array {
        $name = (string) ($registration->participants()->first()?->name ?? '');

        return [$name => $registration];
    });

    $buyerConversion = $conversionFor($byName['Checkout Buyer'] ?? $registrations->first());
    $guestConversion = $conversionFor($byName['Checkout Guest'] ?? $registrations->last());

    expect((string) $buyerConversion->actor_user_id)->toBe((string) $buyer->getKey())
        ->and($guestConversion->actor_user_id)->toBeNull()
        ->and(data_get($buyerConversion->metadata, 'purchaser_user_id'))->toBe((string) $buyer->getKey())
        ->and(data_get($guestConversion->metadata, 'purchaser_user_id'))->toBe((string) $buyer->getKey())
        ->and(data_get($guestConversion->metadata, 'guest'))->toBeTrue();
});

it('fails checkout inheritance closed on unknown refs and self-credit', function (): void {
    $actor = User::factory()->create();

    // An unknown persisted ref can never confer provenance.
    expect(app(ShareTrackingService::class)->recordCheckoutInheritedOutcome(
        DawahShareOutcomeType::EventRegistration,
        ShareOutcomeBusinessKey::eventRegistration((string) Str::uuid()),
        (string) Str::uuid(),
        null,
        $actor,
        [],
        now(),
    ))->toBeNull();

    // Neither can an explicitly unattributed intent.
    expect(app(ShareTrackingService::class)->recordCheckoutInheritedOutcome(
        DawahShareOutcomeType::EventRegistration,
        ShareOutcomeBusinessKey::eventRegistration((string) Str::uuid()),
        null,
        null,
        $actor,
        [],
        now(),
    ))->toBeNull();

    // Proven self-credit stays unattributed even with a real ref.
    $pageUrl = route('events.index');
    $link = wp2lcLink($this->sharer, $pageUrl);
    $cookie = wp2lcLandingCookie($this, $pageUrl.'?share='.$link->custom_slug);

    $selfRequest = Request::create($pageUrl, 'GET');
    $selfRequest->cookies->set((string) config('dawah-share.cookie.name'), $cookie);

    $attributionId = (string) app(ShareTrackingService::class)->resolveActiveAttribution($selfRequest)?->id;

    expect($attributionId)->not->toBe('');

    expect(app(ShareTrackingService::class)->recordCheckoutInheritedOutcome(
        DawahShareOutcomeType::EventRegistration,
        ShareOutcomeBusinessKey::eventRegistration((string) Str::uuid()),
        $attributionId,
        null,
        $this->sharer,
        [],
        now(),
    ))->toBeNull();

    expect(ShareSourceAttributionReceipt::query()->count())->toBe(3)
        ->and(ShareSourceAttributionReceipt::query()->where('provenance', ShareSourceAttributionReceipt::PROVENANCE_CHECKOUT)->count())->toBe(3)
        ->and(AffiliateConversion::query()->count())->toBe(0);
});

it('reverses participation outcomes on event cancel while preserving contributions', function (): void {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $event = wp2lcRegistrationEvent();
    $link = wp2lcLink($this->sharer, route('events.show', $event));
    $cookie = wp2lcLandingCookie($this, route('events.show', $event).'?share='.$link->custom_slug);

    $this->withCredentials()
        ->withCookie((string) config('dawah-share.cookie.name'), $cookie)
        ->postJson(route('api.events.registrations.store', $event), [
            'name' => 'Cancel Probe Guest',
            'email' => 'cancel-probe-guest@example.test',
        ])
        ->assertCreated();

    $registration = Registration::query()->where('event_id', $event->getKey())->firstOrFail();
    $participant = $registration->participants()->firstOrFail();
    $staff = User::factory()->create();
    $staff->assignRole('super_admin');

    $checkinResult = app(RecordEventParticipantCheckInAction::class)->handle($event, $participant, $staff);

    expect($checkinResult['status'])->toBe('created');

    $actor = User::factory()->create();
    $submission = EventSubmission::factory()->create(['event_id' => $event->getKey()]);

    $attributedRequest = Request::create(route('events.show', $event), 'GET');
    $attributedRequest->cookies->set((string) config('dawah-share.cookie.name'), $cookie);

    expect(app(ShareTrackingService::class)->recordOutcome(
        DawahShareOutcomeType::EventSubmission,
        ShareOutcomeBusinessKey::eventSubmission((string) $submission->getKey()),
        $event,
        $actor,
        $attributedRequest,
    ))->not->toBeNull();

    $otherEvent = wp2lcRegistrationEvent();
    $otherLink = wp2lcLink($this->sharer, route('events.show', $otherEvent));
    $otherCookie = wp2lcLandingCookie($this, route('events.show', $otherEvent).'?share='.$otherLink->custom_slug);

    $this->withCredentials()
        ->withCookie((string) config('dawah-share.cookie.name'), $otherCookie)
        ->postJson(route('api.events.registrations.store', $otherEvent), [
            'name' => 'Other Event Guest',
            'email' => 'other-event-guest@example.test',
        ])
        ->assertCreated();

    $otherRegistration = Registration::query()->where('event_id', $otherEvent->getKey())->firstOrFail();

    $registrationKey = ShareOutcomeBusinessKey::eventRegistration((string) $registration->getKey());
    $checkinKey = ShareOutcomeBusinessKey::eventCheckin((string) $checkinResult['checkin']->getKey());
    $submissionKey = ShareOutcomeBusinessKey::eventSubmission((string) $submission->getKey());
    $otherKey = ShareOutcomeBusinessKey::eventRegistration((string) $otherRegistration->getKey());

    expect(AffiliateConversion::query()->count())->toBe(4);

    app(ModerationService::class)->cancel($event, $moderator, 'Venue emergency closure.');

    expect((string) $event->fresh()->status)->toBe('cancelled');

    // Current validity updates with the source transition: the cancelled
    // event's participation facts reverse with corrections and cancelled
    // effects, while the submission contribution, the other event, and
    // all frozen history stand.
    expect(wp2lcConversionStatus(AffiliateConversion::query()->where('idempotency_key', hash('sha256', $registrationKey))->firstOrFail()))->toBe('reversed')
        ->and(ShareOutcomeCorrection::query()->where('business_key', $registrationKey)->value('reason'))->toBe('event_cancelled')
        ->and(wp2lcConversionStatus(AffiliateConversion::query()->where('idempotency_key', hash('sha256', $checkinKey))->firstOrFail()))->toBe('reversed')
        ->and(ShareOutcomeCorrection::query()->where('business_key', $checkinKey)->value('reason'))->toBe('event_cancelled')
        ->and(wp2lcConversionStatus(AffiliateConversion::query()->where('idempotency_key', hash('sha256', $submissionKey))->firstOrFail()))->toBe('approved')
        ->and(wp2lcConversionStatus(AffiliateConversion::query()->where('idempotency_key', hash('sha256', $otherKey))->firstOrFail()))->toBe('approved')
        ->and(ShareSourceAttributionReceipt::query()->where('business_key', $registrationKey)->firstOrFail()->isAttributed())->toBeTrue()
        ->and(ShareEffectReceipt::query()->where('source_id', $registrationKey)->firstOrFail()->status)->toBe(ShareEffectStatus::Cancelled)
        ->and(ShareEffectReceipt::query()->where('source_id', $checkinKey)->firstOrFail()->status)->toBe(ShareEffectStatus::Cancelled);

    // Correction retries stay idempotent: no duplicate entries.
    app(ReverseEventParticipationOutcomesAction::class)->forEvent($event->fresh(), $moderator, 'event_cancelled');

    expect(ShareOutcomeCorrection::query()->count())->toBe(2);
});

it('scopes occurrence and session cancellations to their actual registrations', function (): void {
    $event = wp2lcRegistrationEvent();
    $link = wp2lcLink($this->sharer, route('events.show', $event));
    $cookie = wp2lcLandingCookie($this, route('events.show', $event).'?share='.$link->custom_slug);

    $occurrenceA = EventOccurrence::factory()->create(['event_id' => $event->getKey()]);
    $occurrenceB = EventOccurrence::factory()->create(['event_id' => $event->getKey()]);
    $session = EventSession::factory()->create([
        'event_id' => $event->getKey(),
        'event_occurrence_id' => $occurrenceB->getKey(),
    ]);

    $attributedRequest = Request::create(route('events.show', $event), 'GET');
    $attributedRequest->cookies->set((string) config('dawah-share.cookie.name'), $cookie);

    $service = app(ShareTrackingService::class);
    $registrations = app(RegistrationServiceInterface::class);

    $makeRegistration = function (?string $occurrenceId, ?string $sessionId, string $name) use ($event, $registrations): Registration {
        $registration = $registrations->register([
            'event_id' => $event->getKey(),
            'event_occurrence_id' => $occurrenceId,
            'event_session_id' => $sessionId,
            'registration_type' => 'individual',
            'status' => 'confirmed',
            'source' => 'wp2-probe',
            'total_participants' => 1,
            'participants' => [['name' => $name, 'is_primary' => true]],
        ]);

        return Registration::query()->whereKey($registration->getKey())->firstOrFail();
    };

    $eventLevel = $makeRegistration(null, null, 'Event Level Guest');
    $scopedA = $makeRegistration((string) $occurrenceA->getKey(), null, 'Occurrence A Guest');
    $scopedSession = $makeRegistration((string) $occurrenceB->getKey(), (string) $session->getKey(), 'Session Guest');

    foreach ([$eventLevel, $scopedA, $scopedSession] as $registration) {
        expect($service->recordOutcome(
            DawahShareOutcomeType::EventRegistration,
            ShareOutcomeBusinessKey::eventRegistration((string) $registration->getKey()),
            $event,
            null,
            $attributedRequest,
        ))->not->toBeNull();
    }

    $eventKey = ShareOutcomeBusinessKey::eventRegistration((string) $eventLevel->getKey());
    $keyA = ShareOutcomeBusinessKey::eventRegistration((string) $scopedA->getKey());
    $keyS = ShareOutcomeBusinessKey::eventRegistration((string) $scopedSession->getKey());

    OwnerContext::withOwner(null, fn (): mixed => $occurrenceA->cancel('WP2 occurrence probe'));

    expect(wp2lcConversionStatus(AffiliateConversion::query()->where('idempotency_key', hash('sha256', $keyA))->firstOrFail()))->toBe('reversed')
        ->and(ShareOutcomeCorrection::query()->where('business_key', $keyA)->value('reason'))->toBe('occurrence_cancelled')
        ->and(wp2lcConversionStatus(AffiliateConversion::query()->where('idempotency_key', hash('sha256', $keyS))->firstOrFail()))->toBe('approved')
        ->and(wp2lcConversionStatus(AffiliateConversion::query()->where('idempotency_key', hash('sha256', $eventKey))->firstOrFail()))->toBe('approved');

    OwnerContext::withOwner(null, fn (): mixed => $session->cancel('WP2 session probe'));

    expect(wp2lcConversionStatus(AffiliateConversion::query()->where('idempotency_key', hash('sha256', $keyS))->firstOrFail()))->toBe('reversed')
        ->and(ShareOutcomeCorrection::query()->where('business_key', $keyS)->value('reason'))->toBe('session_cancelled')
        ->and(wp2lcConversionStatus(AffiliateConversion::query()->where('idempotency_key', hash('sha256', $eventKey))->firstOrFail()))->toBe('approved')
        ->and(ShareOutcomeCorrection::query()->count())->toBe(2);
});

it('never late-credits an idempotent registration replay through a new cookie', function (): void {
    $event = wp2lcRegistrationEvent();
    $actor = User::factory()->create();
    $link = wp2lcLink($this->sharer, route('events.show', $event));
    $cookie = wp2lcLandingCookie($this, route('events.show', $event).'?share='.$link->custom_slug);

    $participants = [[
        'name' => $actor->name,
        'email' => $actor->email,
        'phone' => $actor->phone,
        'is_primary' => true,
        'is_purchaser' => true,
    ]];

    $action = app(RegisterEventWithShareProvenanceAction::class);

    // The original transition carries no cookie: explicitly
    // unattributed and earning nothing.
    $first = $action->handle($event, $participants, $actor, ['idempotency_key' => 'wp2-replay-probe'], null);
    $registration = $first->firstOrFail();
    $businessKey = ShareOutcomeBusinessKey::eventRegistration((string) $registration->getKey());

    expect(ShareSourceAttributionReceipt::query()->where('business_key', $businessKey)->firstOrFail()->isAttributed())->toBeFalse()
        ->and(AffiliateConversion::query()->count())->toBe(0);

    // The idempotent retry returns the same registration and must not
    // freeze a new decision from the fresh landing cookie.
    $attributedRequest = Request::create(route('events.show', $event), 'GET');
    $attributedRequest->cookies->set((string) config('dawah-share.cookie.name'), $cookie);

    $second = $action->handle($event, $participants, $actor, ['idempotency_key' => 'wp2-replay-probe'], $attributedRequest);

    expect((string) $second->firstOrFail()->getKey())->toBe((string) $registration->getKey())
        ->and(ShareSourceAttributionReceipt::query()->where('business_key', $businessKey)->firstOrFail()->isAttributed())->toBeFalse()
        ->and(AffiliateConversion::query()->count())->toBe(0);
});

it('freezes missing checkout provenance unattributed and blocks later repair credit', function (): void {
    config()->set('events.features.commerce.public_paid_checkout_enabled', true);

    [$event, $ticket] = wp2lcPaidEvent();
    $buyer = User::factory()->create();

    $link = wp2lcLink($this->sharer, route('events.show', $event));
    $cookie = wp2lcLandingCookie($this, route('events.show', $event).'?share='.$link->custom_slug);

    $attributedRequest = Request::create(route('events.show', $event), 'GET');
    $attributedRequest->cookies->set((string) config('dawah-share.cookie.name'), $cookie);

    $session = OwnerContext::withOwner(null, fn () => app(PrepareEventCheckoutAction::class)->handle(
        event: $event,
        ticketType: $ticket,
        quantity: 1,
        participants: [array_merge(wp2lcCheckoutParticipant('Missing Probe', true), [
            'participant_type' => $buyer->getMorphClass(),
            'participant_id' => (string) $buyer->getKey(),
        ])],
        buyer: $buyer,
        agreement: ['version' => 'event-participation-v1'],
        idempotencyKey: 'wp2-checkout-missing',
        request: $attributedRequest,
    ));

    $persistedAttributionId = data_get($session->payment_data, 'share_provenance.attribution_id');
    expect($persistedAttributionId)->not->toBeNull();

    $order = OwnerContext::withOwner(null, fn () => app(OrderServiceInterface::class)->createOrder(
        orderData: [
            'customer_id' => (string) $buyer->getKey(),
            'customer_type' => $buyer->getMorphClass(),
            'subtotal' => 3500,
            'discount_total' => 0,
            'shipping_total' => 0,
            'tax_total' => 0,
            'grand_total' => 3500,
            'currency' => 'MYR',
            'metadata' => ['event_id' => (string) $event->getKey(), 'event_fulfillment' => 'event_registration'],
        ],
        items: [[
            'purchasable_id' => $ticket->getKey(),
            'purchasable_type' => $ticket->getMorphClass(),
            'name' => (string) $ticket->name,
            'sku' => (string) $ticket->code,
            'quantity' => 1,
            'unit_price' => 3500,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'currency' => 'MYR',
        ]],
        intakeSource: 'checkout',
        intakeId: (string) $session->getKey(),
    ));

    $session->forceFill(['order_id' => $order->getKey()])->save();

    expect(OwnerContext::withOwner(null, fn () => app(CreateEventRegistrationsStep::class)->handle($session->fresh()))->isSuccessful())->toBeTrue();

    $paymentData = $session->fresh()->payment_data ?? [];
    unset($paymentData['share_provenance']);
    $session->forceFill(['payment_data' => $paymentData])->save();

    $first = OwnerContext::withOwner(null, fn () => app(RecordCheckoutShareProvenanceStep::class)->handle($session->fresh()));
    expect($first->isSuccessful())->toBeTrue();

    $registration = Registration::query()->where('event_id', $event->getKey())->firstOrFail();
    $businessKey = ShareOutcomeBusinessKey::eventRegistration((string) $registration->getKey());

    expect(ShareSourceAttributionReceipt::query()->where('business_key', $businessKey)->firstOrFail()->isAttributed())->toBeFalse()
        ->and(AffiliateConversion::query()->count())->toBe(0);

    $repaired = $session->fresh()->payment_data ?? [];
    $repaired['share_provenance'] = [
        'intent_id' => (string) $session->getKey(),
        'attribution_id' => $persistedAttributionId,
        'occurred_at' => now()->toIso8601String(),
    ];
    $session->forceFill(['payment_data' => $repaired])->save();

    $second = OwnerContext::withOwner(null, fn () => app(RecordCheckoutShareProvenanceStep::class)->handle($session->fresh()));
    expect($second->isSuccessful())->toBeTrue();
    expect(ShareSourceAttributionReceipt::query()->where('business_key', $businessKey)->firstOrFail()->isAttributed())->toBeFalse()
        ->and(AffiliateConversion::query()->count())->toBe(0);
});

it('freezes mismatched checkout intents unattributed and blocks later repair credit', function (): void {
    config()->set('events.features.commerce.public_paid_checkout_enabled', true);

    [$event, $ticket] = wp2lcPaidEvent();
    $buyer = User::factory()->create();

    $link = wp2lcLink($this->sharer, route('events.show', $event));
    $cookie = wp2lcLandingCookie($this, route('events.show', $event).'?share='.$link->custom_slug);

    $attributedRequest = Request::create(route('events.show', $event), 'GET');
    $attributedRequest->cookies->set((string) config('dawah-share.cookie.name'), $cookie);

    $session = OwnerContext::withOwner(null, fn () => app(PrepareEventCheckoutAction::class)->handle(
        event: $event,
        ticketType: $ticket,
        quantity: 1,
        participants: [array_merge(wp2lcCheckoutParticipant('Mismatch Probe', true), [
            'participant_type' => $buyer->getMorphClass(),
            'participant_id' => (string) $buyer->getKey(),
        ])],
        buyer: $buyer,
        agreement: ['version' => 'event-participation-v1'],
        idempotencyKey: 'wp2-checkout-mismatch',
        request: $attributedRequest,
    ));

    $persistedAttributionId = data_get($session->payment_data, 'share_provenance.attribution_id');
    expect($persistedAttributionId)->not->toBeNull();

    $order = OwnerContext::withOwner(null, fn () => app(OrderServiceInterface::class)->createOrder(
        orderData: [
            'customer_id' => (string) $buyer->getKey(),
            'customer_type' => $buyer->getMorphClass(),
            'subtotal' => 3500,
            'discount_total' => 0,
            'shipping_total' => 0,
            'tax_total' => 0,
            'grand_total' => 3500,
            'currency' => 'MYR',
            'metadata' => ['event_id' => (string) $event->getKey(), 'event_fulfillment' => 'event_registration'],
        ],
        items: [[
            'purchasable_id' => $ticket->getKey(),
            'purchasable_type' => $ticket->getMorphClass(),
            'name' => (string) $ticket->name,
            'sku' => (string) $ticket->code,
            'quantity' => 1,
            'unit_price' => 3500,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'currency' => 'MYR',
        ]],
        intakeSource: 'checkout',
        intakeId: (string) $session->getKey(),
    ));

    $session->forceFill(['order_id' => $order->getKey()])->save();

    expect(OwnerContext::withOwner(null, fn () => app(CreateEventRegistrationsStep::class)->handle($session->fresh()))->isSuccessful())->toBeTrue();

    $tampered = $session->fresh()->payment_data ?? [];
    $tampered['share_provenance']['intent_id'] = (string) Str::uuid();
    $session->forceFill(['payment_data' => $tampered])->save();

    $first = OwnerContext::withOwner(null, fn () => app(RecordCheckoutShareProvenanceStep::class)->handle($session->fresh()));
    expect($first->isSuccessful())->toBeTrue();

    $registration = Registration::query()->where('event_id', $event->getKey())->firstOrFail();
    $businessKey = ShareOutcomeBusinessKey::eventRegistration((string) $registration->getKey());

    expect(ShareSourceAttributionReceipt::query()->where('business_key', $businessKey)->firstOrFail()->isAttributed())->toBeFalse()
        ->and(AffiliateConversion::query()->count())->toBe(0);

    $repaired = $session->fresh()->payment_data ?? [];
    $repaired['share_provenance']['intent_id'] = (string) $session->getKey();
    $session->forceFill(['payment_data' => $repaired])->save();

    $second = OwnerContext::withOwner(null, fn () => app(RecordCheckoutShareProvenanceStep::class)->handle($session->fresh()));
    expect($second->isSuccessful())->toBeTrue();
    expect(ShareSourceAttributionReceipt::query()->where('business_key', $businessKey)->firstOrFail()->isAttributed())->toBeFalse()
        ->and(AffiliateConversion::query()->count())->toBe(0);
});

it('uses the trusted intent timestamp for corrupt checkout provenance clocks', function (): void {
    config()->set('events.features.commerce.public_paid_checkout_enabled', true);

    [$event, $ticket] = wp2lcPaidEvent();
    $buyer = User::factory()->create();

    $link = wp2lcLink($this->sharer, route('events.show', $event));
    $cookie = wp2lcLandingCookie($this, route('events.show', $event).'?share='.$link->custom_slug);

    $attributedRequest = Request::create(route('events.show', $event), 'GET');
    $attributedRequest->cookies->set((string) config('dawah-share.cookie.name'), $cookie);

    $session = OwnerContext::withOwner(null, fn () => app(PrepareEventCheckoutAction::class)->handle(
        event: $event,
        ticketType: $ticket,
        quantity: 1,
        participants: [array_merge(wp2lcCheckoutParticipant('Clock Probe', true), [
            'participant_type' => $buyer->getMorphClass(),
            'participant_id' => (string) $buyer->getKey(),
        ])],
        buyer: $buyer,
        agreement: ['version' => 'event-participation-v1'],
        idempotencyKey: 'wp2-checkout-clock',
        request: $attributedRequest,
    ));

    $order = OwnerContext::withOwner(null, fn () => app(OrderServiceInterface::class)->createOrder(
        orderData: [
            'customer_id' => (string) $buyer->getKey(),
            'customer_type' => $buyer->getMorphClass(),
            'subtotal' => 3500,
            'discount_total' => 0,
            'shipping_total' => 0,
            'tax_total' => 0,
            'grand_total' => 3500,
            'currency' => 'MYR',
            'metadata' => ['event_id' => (string) $event->getKey(), 'event_fulfillment' => 'event_registration'],
        ],
        items: [[
            'purchasable_id' => $ticket->getKey(),
            'purchasable_type' => $ticket->getMorphClass(),
            'name' => (string) $ticket->name,
            'sku' => (string) $ticket->code,
            'quantity' => 1,
            'unit_price' => 3500,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'currency' => 'MYR',
        ]],
        intakeSource: 'checkout',
        intakeId: (string) $session->getKey(),
    ));

    $session->forceFill(['order_id' => $order->getKey()])->save();

    expect(OwnerContext::withOwner(null, fn () => app(CreateEventRegistrationsStep::class)->handle($session->fresh()))->isSuccessful())->toBeTrue();

    $corrupt = $session->fresh()->payment_data ?? [];
    $corrupt['share_provenance']['occurred_at'] = 'not-a-timestamp';
    $session->forceFill(['payment_data' => $corrupt])->save();

    $result = OwnerContext::withOwner(null, fn () => app(RecordCheckoutShareProvenanceStep::class)->handle($session->fresh()));
    expect($result->isSuccessful())->toBeTrue();

    $registration = Registration::query()->where('event_id', $event->getKey())->firstOrFail();
    $receipt = ShareSourceAttributionReceipt::query()
        ->where('business_key', ShareOutcomeBusinessKey::eventRegistration((string) $registration->getKey()))
        ->firstOrFail();

    expect($receipt->isAttributed())->toBeTrue();
    expect($receipt->occurred_at->toIso8601String())->toBe($session->fresh()->created_at->toIso8601String());
});

it('freezes paid registrations unattributed when scope cancels before fulfillment', function (): void {
    config()->set('events.features.commerce.public_paid_checkout_enabled', true);

    [$event, $ticket] = wp2lcPaidEvent();
    $buyer = User::factory()->create();
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');

    $link = wp2lcLink($this->sharer, route('events.show', $event));
    $cookie = wp2lcLandingCookie($this, route('events.show', $event).'?share='.$link->custom_slug);

    $attributedRequest = Request::create(route('events.show', $event), 'GET');
    $attributedRequest->cookies->set((string) config('dawah-share.cookie.name'), $cookie);

    $session = OwnerContext::withOwner(null, fn () => app(PrepareEventCheckoutAction::class)->handle(
        event: $event,
        ticketType: $ticket,
        quantity: 1,
        participants: [array_merge(wp2lcCheckoutParticipant('Scope Probe', true), [
            'participant_type' => $buyer->getMorphClass(),
            'participant_id' => (string) $buyer->getKey(),
        ])],
        buyer: $buyer,
        agreement: ['version' => 'event-participation-v1'],
        idempotencyKey: 'wp2-checkout-scopecancel',
        request: $attributedRequest,
    ));

    $order = OwnerContext::withOwner(null, fn () => app(OrderServiceInterface::class)->createOrder(
        orderData: [
            'customer_id' => (string) $buyer->getKey(),
            'customer_type' => $buyer->getMorphClass(),
            'subtotal' => 3500,
            'discount_total' => 0,
            'shipping_total' => 0,
            'tax_total' => 0,
            'grand_total' => 3500,
            'currency' => 'MYR',
            'metadata' => ['event_id' => (string) $event->getKey(), 'event_fulfillment' => 'event_registration'],
        ],
        items: [[
            'purchasable_id' => $ticket->getKey(),
            'purchasable_type' => $ticket->getMorphClass(),
            'name' => (string) $ticket->name,
            'sku' => (string) $ticket->code,
            'quantity' => 1,
            'unit_price' => 3500,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'currency' => 'MYR',
        ]],
        intakeSource: 'checkout',
        intakeId: (string) $session->getKey(),
    ));

    $session->forceFill(['order_id' => $order->getKey()])->save();

    expect(OwnerContext::withOwner(null, fn () => app(CreateEventRegistrationsStep::class)->handle($session->fresh()))->isSuccessful())->toBeTrue();

    app(ModerationService::class)->cancel($event->fresh(), $moderator, 'Scope cancelled before callback.');
    expect((string) $event->fresh()->status)->toBe('cancelled');

    $result = OwnerContext::withOwner(null, fn () => app(RecordCheckoutShareProvenanceStep::class)->handle($session->fresh()));
    expect($result->isSuccessful())->toBeTrue();

    $registration = Registration::query()->where('event_id', $event->getKey())->firstOrFail();
    $businessKey = ShareOutcomeBusinessKey::eventRegistration((string) $registration->getKey());

    expect(ShareSourceAttributionReceipt::query()->where('business_key', $businessKey)->firstOrFail()->isAttributed())->toBeFalse()
        ->and(AffiliateConversion::query()->count())->toBe(0);
});
