<?php

declare(strict_types=1);

// WP2 real-driver OS-process race proof.
//
// Two independent OS processes with independent connections run the actual
// current flows behind a start barrier on worker-namespaced databases
// built from the current original migrations on the isolated PostgreSQL
// state (one frozen decision, one projection, coherent correction), never
// timing and never a sleep. If the receipt-first freeze, the browser
// selection lock, the dispatcher lease fencing, or the correction
// idempotency were deleted, these tests fail.
//
// Guarded test-only worker databases: loopback isolated ports/databases
// only. Never the live application database (ilmu360 / 127.0.0.1:5432).

use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateAttribution;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateLink;
use AIArmada\Affiliates\Models\AffiliateTouchpoint;
use AIArmada\Affiliates\States\ConversionStatus;
use AIArmada\Cart\Models\CartModel;
use AIArmada\Checkout\Models\CheckoutSession;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Actions\SyncPrimaryEventOccurrenceAction;
use AIArmada\Events\Enums\ScheduleKind;
use AIArmada\Inventory\Models\InventoryLocation;
use AIArmada\Seating\Enums\SeatingMode;
use AIArmada\Ticketing\Models\TicketType;
use App\Actions\Events\Commerce\PrepareEventCheckoutAction;
use App\Enums\DawahShareOutcomeType;
use App\Enums\ShareEffectStatus;
use App\Models\Event;
use App\Models\ShareBrowserSelection;
use App\Models\ShareEffectReceipt;
use App\Models\ShareOutcomeCorrection;
use App\Models\ShareSourceAttributionReceipt;
use App\Models\User;
use App\Services\ShareTrackingService;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\Feature\ShareTracking\Wp1RealDriverHarness;
use Tests\Feature\ShareTracking\Wp2RealDriverHarness;
use Tests\Support\RealDriverFixture;
use Tests\TestCase;

/**
 * @return array{0: string, 1: string}
 */
function wp2RaceWorker(string $test): array
{
    $database = RealDriverFixture::createWorkerDatabase($test);
    $previous = RealDriverFixture::useWorkerDatabase($database, Wp1RealDriverHarness::WORKER_CONNECTION);

    try {
        Wp2RealDriverHarness::migrateWorkerDatabase();

    } catch (Throwable $exception) {
        RealDriverFixture::restoreDatabase($previous, Wp1RealDriverHarness::WORKER_CONNECTION);
        RealDriverFixture::dropDatabase($database);

        throw $exception;
    }

    return [$database, $previous];
}

function wp2RaceTeardown(string $database, string $previous): void
{
    RealDriverFixture::restoreDatabase($previous, Wp1RealDriverHarness::WORKER_CONNECTION);
    RealDriverFixture::dropDatabase($database);
}

function wp2RaceLink(User $sharer, string $url): AffiliateLink
{
    app(ShareTrackingService::class)->createOrReuseLink($sharer, $url, 'Majlis', 'web');

    $affiliate = Affiliate::query()
        ->where('owner_type', $sharer->getMorphClass())
        ->where('owner_id', $sharer->getKey())
        ->firstOrFail();

    return AffiliateLink::query()->where('affiliate_id', $affiliate->getKey())->firstOrFail();
}

function wp2RaceLanding(string $url, ?string $cookie = null): ?string
{
    return app(ShareTrackingService::class)->captureRequest(
        Wp2RealDriverHarness::landingRequest($url, $cookie)
    );
}

function wp2AttributedUnattributedRaceCase(TestCase $testCase): void
{
    [$database, $previous] = wp2RaceWorker('attrunattr');

    try {
        $pageUrl = route('events.index');
        $sharer = User::factory()->create(['email' => 'wp2-sharer@example.test']);
        $actor = User::factory()->create(['email' => 'wp2-actor@example.test']);
        $link = wp2RaceLink($sharer, $pageUrl);
        $cookie = wp2RaceLanding($pageUrl.'?share='.$link->custom_slug);
        $businessKey = ShareOutcomeBusinessKey::eventSave((string) Str::uuid());

        // One worker observes the landing cookie, the other observes no
        // attribution, for the same source transition. Exactly one
        // decision freezes; credit follows the winner only.
        [$first, $second] = Wp2RealDriverHarness::runRace([
            'database' => $database,
            'app_url' => (string) config('app.url'),
            'mode' => 'outcome',
            'payload' => [
                'business_key' => $businessKey,
                'type' => DawahShareOutcomeType::EventSave->value,
                'actor_id' => (string) $actor->getKey(),
                'cookies' => [$cookie, null],
            ],
        ]);

        expect((string) $first['receipt_id'])->toBe((string) $second['receipt_id']);

        $receipt = ShareSourceAttributionReceipt::query()->where('business_key', $businessKey)->firstOrFail();

        expect(ShareSourceAttributionReceipt::query()->where('business_key', $businessKey)->count())->toBe(1);

        if ($receipt->isAttributed()) {
            expect(AffiliateConversion::query()->count())->toBe(1)
                ->and(ShareEffectReceipt::query()->count())->toBe(1);
        } else {
            expect(AffiliateConversion::query()->count())->toBe(0)
                ->and(ShareEffectReceipt::query()->count())->toBe(0);
        }
    } finally {
        wp2RaceTeardown($database, $previous);
    }
}

function wp2DifferentSharerRaceCase(TestCase $testCase): void
{
    [$database, $previous] = wp2RaceWorker('difsharer');

    try {
        $pageUrl = route('events.index');
        $sharerA = User::factory()->create(['email' => 'wp2-sharer-a@example.test']);
        $sharerB = User::factory()->create(['email' => 'wp2-sharer-b@example.test']);
        $actor = User::factory()->create(['email' => 'wp2-actor@example.test']);
        $linkA = wp2RaceLink($sharerA, $pageUrl);
        $linkB = wp2RaceLink($sharerB, $pageUrl);
        $cookieA = wp2RaceLanding($pageUrl.'?share='.$linkA->custom_slug);
        $cookieB = wp2RaceLanding($pageUrl.'?share='.$linkB->custom_slug);
        $businessKey = ShareOutcomeBusinessKey::eventSave((string) Str::uuid());

        // Two tabs credit two different sharers for the same action.
        // Exactly one attribution wins the freeze; one conversion and
        // one effect at most follow the winner.
        [$first, $second] = Wp2RealDriverHarness::runRace([
            'database' => $database,
            'app_url' => (string) config('app.url'),
            'mode' => 'outcome',
            'payload' => [
                'business_key' => $businessKey,
                'type' => DawahShareOutcomeType::EventSave->value,
                'actor_id' => (string) $actor->getKey(),
                'cookies' => [$cookieA, $cookieB],
            ],
        ]);

        expect((string) $first['receipt_id'])->toBe((string) $second['receipt_id']);

        $receipt = ShareSourceAttributionReceipt::query()->where('business_key', $businessKey)->firstOrFail();
        $winnerIds = AffiliateAttribution::query()->pluck('id')->map(static fn ($id): string => (string) $id)->all();

        expect($receipt->isAttributed())->toBeTrue()
            ->and(in_array((string) $receipt->attribution_id, $winnerIds, true))->toBeTrue()
            ->and(ShareSourceAttributionReceipt::query()->where('business_key', $businessKey)->count())->toBe(1)
            ->and(AffiliateConversion::query()->count())->toBe(1)
            ->and(ShareEffectReceipt::query()->count())->toBe(1);
    } finally {
        wp2RaceTeardown($database, $previous);
    }
}

function wp2ConcurrentSelectionRaceCase(TestCase $testCase): void
{
    // Three legs on three fresh worker databases. The concurrent leg
    // captures real OS-process lock contention and pins the coherence
    // shape EVERY commit order must satisfy; the two ordered legs pin
    // each order's deterministic winner, provenance, clocks, and deduped
    // visit counts per the accepted A→B→A semantics. Attributions and
    // selections are immutable: no leg may lose an update.
    wp2SelectionContentionLeg();
    wp2SelectionOrderedLeg('b_then_a');
    wp2SelectionOrderedLeg('a_then_b');
}

function wp2SelectionContentionLeg(): void
{
    [$database, $previous] = wp2RaceWorker('selection');

    try {
        $pageUrl = route('events.index');
        $sharerA = User::factory()->create(['email' => 'wp2-sharer-a@example.test']);
        $sharerB = User::factory()->create(['email' => 'wp2-sharer-b@example.test']);
        $linkA = wp2RaceLink($sharerA, $pageUrl);
        $linkB = wp2RaceLink($sharerB, $pageUrl);
        $cookie = wp2RaceLanding($pageUrl.'?share='.$linkA->custom_slug);

        // One browser opens two different sharers' links concurrently.
        // The persistent selection lock serializes the landings; the
        // winner depends on the commit order, so this leg pins only the
        // order-independent coherence shape. The ordered legs below pin
        // each order's exact winner.
        [$first, $second] = Wp2RealDriverHarness::runRace([
            'database' => $database,
            'app_url' => (string) config('app.url'),
            'mode' => 'landing',
            'payload' => [
                'urls' => [
                    $pageUrl.'?share='.$linkB->custom_slug,
                    $pageUrl.'?share='.$linkA->custom_slug,
                ],
                'cookie' => $cookie,
            ],
        ]);

        expect($first['cookie_returned'])->toBeTrue()
            ->and($second['cookie_returned'])->toBeTrue();

        $attributions = AffiliateAttribution::query()->get();
        $selections = ShareBrowserSelection::query()->get();

        expect($selections)->toHaveCount(1)
            ->and($attributions->count())->toBeGreaterThanOrEqual(2);

        $winnerId = (string) $selections->first()->current_attribution_id;
        $ids = $attributions->map(static fn ($attribution): string => (string) $attribution->getKey())->all();

        expect($ids)->toContain($winnerId);

        $winner = AffiliateAttribution::query()->whereKey($winnerId)->firstOrFail();

        expect([(string) $linkA->getKey(), (string) $linkB->getKey()])->toContain((string) $winner->affiliate_link_id)
            ->and((string) $winner->attribution_type)->toBe('landing')
            ->and(AffiliateTouchpoint::query()->where('touchpoint_type', 'visit')->count())->toBe(2);
    } finally {
        wp2RaceTeardown($database, $previous);
    }
}

function wp2SelectionOrderedLeg(string $order): void
{
    [$database, $previous] = wp2RaceWorker($order === 'b_then_a' ? 'selborder' : 'selaorder');

    try {
        $pageUrl = route('events.index');
        $sharerA = User::factory()->create(['email' => 'wp2-sharer-a@example.test']);
        $sharerB = User::factory()->create(['email' => 'wp2-sharer-b@example.test']);
        $linkA = wp2RaceLink($sharerA, $pageUrl);
        $linkB = wp2RaceLink($sharerB, $pageUrl);
        $cookie = wp2RaceLanding($pageUrl.'?share='.$linkA->custom_slug);
        $initial = AffiliateAttribution::query()->firstOrFail();
        $initialId = (string) $initial->getKey();

        // Slot 0 lands B, slot 1 re-lands A. The turn protocol commits
        // them in the requested order across two real OS processes.
        [$first, $second] = Wp2RealDriverHarness::runOrderedLandings([
            'database' => $database,
            'app_url' => (string) config('app.url'),
            'mode' => 'landing_ordered',
            'payload' => [
                'urls' => [
                    $pageUrl.'?share='.$linkB->custom_slug,
                    $pageUrl.'?share='.$linkA->custom_slug,
                ],
                'cookie' => $cookie,
            ],
        ], $order === 'b_then_a' ? 0 : 1);

        expect($first['cookie_returned'])->toBeTrue()
            ->and($second['cookie_returned'])->toBeTrue()
            ->and(ShareBrowserSelection::query()->count())->toBe(1);

        $selection = ShareBrowserSelection::query()->firstOrFail();
        $winner = AffiliateAttribution::query()->whereKey($selection->current_attribution_id)->firstOrFail();
        $visits = AffiliateTouchpoint::query()->where('touchpoint_type', 'visit')->get();

        $visitsForLink = static fn (mixed $linkId): int => $visits
            ->filter(static fn ($visit): bool => (string) $visit->affiliate_link_id === (string) $linkId)
            ->count();

        if ($order === 'b_then_a') {
            // B lands first, then an explicit A re-landing: A wins with a
            // NEW immutable attribution even though its visit is deduped.
            $aAttributions = AffiliateAttribution::query()
                ->where('affiliate_link_id', $linkA->getKey())
                ->get();
            $aAttributionIds = array_map('strval', $aAttributions->modelKeys());
            expect(AffiliateAttribution::query()->count())->toBe(3)
                ->and($aAttributions)->toHaveCount(2)
                ->and($aAttributionIds)->toContain($initialId)
                ->and($aAttributionIds)->toContain((string) $winner->getKey())
                ->and((string) $winner->getKey())->not->toBe($initialId)
                ->and((string) $winner->affiliate_link_id)->toBe((string) $linkA->getKey())
                ->and((string) $winner->affiliate_id)->toBe((string) $linkA->affiliate_id)
                ->and((string) $winner->visitor_key)->toBe((string) $initial->visitor_key)
                ->and((string) $winner->attribution_type)->toBe('landing')
                ->and((string) $winner->origin)->toBe('web')
                ->and((string) $winner->landing_url)->not->toContain('share=')
                ->and($winner->first_seen_at)->not->toBeNull()
                ->and($selection->accepted_at)->not->toBeNull()
                ->and($visits)->toHaveCount(2)
                ->and($visitsForLink($linkA->getKey()))->toBe(1)
                ->and($visitsForLink($linkB->getKey()))->toBe(1);

            return;
        }

        // A refreshes first (a deduped same landing: no new attribution,
        // no new visit), then B lands and wins the selection.
        expect(AffiliateAttribution::query()->count())->toBe(2)
            ->and(AffiliateAttribution::query()->where('affiliate_link_id', $linkA->getKey())->count())->toBe(1)
            ->and((string) $winner->affiliate_link_id)->toBe((string) $linkB->getKey())
            ->and((string) $winner->affiliate_id)->toBe((string) $linkB->affiliate_id)
            ->and((string) $winner->visitor_key)->toBe((string) $initial->visitor_key)
            ->and((string) $winner->attribution_type)->toBe('landing')
            ->and((string) $winner->origin)->toBe('web')
            ->and((string) $winner->landing_url)->not->toContain('share=')
            ->and($initial->first_seen_at)->not->toBeNull()
            ->and($winner->first_seen_at)->not->toBeNull()
            ->and($selection->accepted_at)->not->toBeNull()
            ->and($visits)->toHaveCount(2)
            ->and($visitsForLink($linkA->getKey()))->toBe(1)
            ->and($visitsForLink($linkB->getKey()))->toBe(1);
    } finally {
        wp2RaceTeardown($database, $previous);
    }
}

function wp2DispatchFencingRaceCase(TestCase $testCase): void
{
    [$database, $previous] = wp2RaceWorker('fencing');

    try {
        $pageUrl = route('events.index');
        $sharer = User::factory()->create(['email' => 'wp2-sharer@example.test']);
        $actor = User::factory()->create(['email' => 'wp2-actor@example.test']);
        $link = wp2RaceLink($sharer, $pageUrl);
        $cookie = wp2RaceLanding($pageUrl.'?share='.$link->custom_slug);

        $request = Request::create((string) config('app.url'), 'GET');
        $request->cookies->set((string) config('dawah-share.cookie.name', 'mi_dawah_share'), (string) $cookie);

        app(ShareTrackingService::class)->recordOutcome(
            DawahShareOutcomeType::EventSave,
            ShareOutcomeBusinessKey::eventSave((string) Str::uuid()),
            null,
            $actor,
            $request,
        );

        // Two dispatcher workers race the same pending receipt. Lease
        // fencing lets exactly one claim+apply: the projection moves by
        // one and the loser processes nothing.
        [$first, $second] = Wp2RealDriverHarness::runRace([
            'database' => $database,
            'app_url' => (string) config('app.url'),
            'mode' => 'dispatch',
            'payload' => [],
        ]);

        expect((int) $first['processed'] + (int) $second['processed'])->toBe(1)
            ->and((int) $link->fresh()->conversions)->toBe(1)
            ->and(ShareEffectReceipt::query()->firstOrFail()->status)->toBe(ShareEffectStatus::Succeeded)
            ->and((int) ShareEffectReceipt::query()->firstOrFail()->attempts)->toBe(1);
    } finally {
        wp2RaceTeardown($database, $previous);
    }
}

function wp2CorrectionRaceCase(TestCase $testCase): void
{
    [$database, $previous] = wp2RaceWorker('correction');

    try {
        $pageUrl = route('events.index');
        $sharer = User::factory()->create(['email' => 'wp2-sharer@example.test']);
        $actor = User::factory()->create(['email' => 'wp2-actor@example.test']);
        $link = wp2RaceLink($sharer, $pageUrl);
        $cookie = wp2RaceLanding($pageUrl.'?share='.$link->custom_slug);
        $businessKey = ShareOutcomeBusinessKey::eventSave((string) Str::uuid());

        // Recording races a correction for a fresh key. Both orders are
        // coherent: either no correction with a valid fact, or exactly
        // one correction with a reversed fact and cancelled effects.
        Wp2RealDriverHarness::runRace([
            'database' => $database,
            'app_url' => (string) config('app.url'),
            'mode' => 'correction',
            'payload' => [
                'business_key' => $businessKey,
                'type' => DawahShareOutcomeType::EventSave->value,
                'actor_id' => (string) $actor->getKey(),
                'cookies' => [$cookie, null],
            ],
        ]);

        expect(ShareSourceAttributionReceipt::query()->where('business_key', $businessKey)->count())->toBe(1)
            ->and(ShareOutcomeCorrection::query()->where('business_key', $businessKey)->count())->toBeLessThanOrEqual(1);

        $conversion = AffiliateConversion::query()->first();

        expect($conversion)->not->toBeNull();

        if (ShareOutcomeCorrection::query()->where('business_key', $businessKey)->exists()) {
            $status = $conversion->status;

            $value = $status instanceof ConversionStatus
                ? (string) $status->getValue()
                : (string) $status;

            expect($value)->toBe('reversed')
                ->and(ShareEffectReceipt::query()->firstOrFail()->status)->toBe(ShareEffectStatus::Cancelled);
        } else {
            $status = $conversion->status;

            $value = $status instanceof ConversionStatus
                ? (string) $status->getValue()
                : (string) $status;

            expect($value)->toBe('approved')
                ->and(ShareEffectReceipt::query()->firstOrFail()->status)->toBe(ShareEffectStatus::Pending);
        }
    } finally {
        wp2RaceTeardown($database, $previous);
    }
}

function wp2NavigationLandingRaceCase(TestCase $testCase): void
{
    [$database, $previous] = wp2RaceWorker('navlanding');

    try {
        $pageUrl = route('events.index');
        $sharerA = User::factory()->create(['email' => 'wp2-sharer-a@example.test']);
        $sharerB = User::factory()->create(['email' => 'wp2-sharer-b@example.test']);
        $linkA = wp2RaceLink($sharerA, $pageUrl);
        $linkB = wp2RaceLink($sharerB, $pageUrl);
        $cookieA = wp2RaceLanding($pageUrl.'?share='.$linkA->custom_slug);

        Wp2RealDriverHarness::runRace([
            'database' => $database,
            'app_url' => (string) config('app.url'),
            'mode' => 'landing',
            'payload' => [
                'urls' => [
                    $pageUrl,
                    $pageUrl.'?share='.$linkB->custom_slug,
                ],
                'cookie' => $cookieA,
            ],
        ]);

        $winnerB = AffiliateAttribution::query()->where('affiliate_link_id', $linkB->getKey())->latest('first_seen_at')->firstOrFail();
        $selection = ShareBrowserSelection::query()->firstOrFail();

        expect(ShareBrowserSelection::query()->count())->toBe(1);
        expect((string) $selection->current_attribution_id)->toBe((string) $winnerB->getKey());
        expect(AffiliateAttribution::query()->where('attribution_type', 'landing')->count())->toBe(2);
    } finally {
        wp2RaceTeardown($database, $previous);
    }
}

function wp2InheritCorrectionRaceCase(TestCase $testCase): void
{
    [$database, $previous] = wp2RaceWorker('inheritcorr');

    try {
        $pageUrl = route('events.index');
        $sharer = User::factory()->create(['email' => 'wp2-sharer@example.test']);
        $actor = User::factory()->create(['email' => 'wp2-actor@example.test']);
        $link = wp2RaceLink($sharer, $pageUrl);
        $cookie = wp2RaceLanding($pageUrl.'?share='.$link->custom_slug);

        $request = Request::create((string) config('app.url'), 'GET');
        $request->cookies->set((string) config('dawah-share.cookie.name', 'mi_dawah_share'), (string) $cookie);

        $sourceKey = ShareOutcomeBusinessKey::eventRegistration((string) Str::uuid());
        $childKey = ShareOutcomeBusinessKey::eventCheckin((string) Str::uuid());

        expect(app(ShareTrackingService::class)->recordOutcome(
            DawahShareOutcomeType::EventRegistration,
            $sourceKey,
            null,
            $actor,
            $request,
        ))->not->toBeNull();

        Wp2RealDriverHarness::runRace([
            'database' => $database,
            'app_url' => (string) config('app.url'),
            'mode' => 'inherit_correction',
            'payload' => [
                'source_key' => $sourceKey,
                'child_key' => $childKey,
                'actor_id' => (string) $actor->getKey(),
            ],
        ]);

        expect(ShareOutcomeCorrection::query()->where('business_key', $sourceKey)->count())->toBe(1);

        $sourceConversion = AffiliateConversion::query()->where('idempotency_key', hash('sha256', $sourceKey))->firstOrFail();
        $sourceStatus = $sourceConversion->status;
        $sourceValue = $sourceStatus instanceof ConversionStatus ? (string) $sourceStatus->getValue() : (string) $sourceStatus;
        expect($sourceValue)->toBe('reversed');

        $childConversion = AffiliateConversion::query()->where('idempotency_key', hash('sha256', $childKey))->first();

        if ($childConversion instanceof AffiliateConversion) {
            $childStatus = $childConversion->status;
            $childValue = $childStatus instanceof ConversionStatus ? (string) $childStatus->getValue() : (string) $childStatus;
            expect($childValue)->toBe('reversed');
            expect(ShareEffectReceipt::query()->where('source_id', $childKey)->firstOrFail()->status)->toBe(ShareEffectStatus::Cancelled);
        } else {
            expect(ShareSourceAttributionReceipt::query()->where('business_key', $childKey)->firstOrFail()->isAttributed())->toBeFalse();
            expect(ShareEffectReceipt::query()->where('source_id', $childKey)->count())->toBe(0);
        }

        expect(AffiliateConversion::query()->where('idempotency_key', hash('sha256', $childKey))->whereNotIn('status', ['rejected', 'reversed'])->count())->toBe(0);
        expect(ShareEffectReceipt::query()->where('source_id', $childKey)->where('status', ShareEffectStatus::Succeeded->value)->count())->toBe(0);
    } finally {
        wp2RaceTeardown($database, $previous);
    }
}

function wp2PrepareRaceCase(TestCase $testCase): void
{
    $database = RealDriverFixture::createWorkerDatabase('preparerace');
    $previous = RealDriverFixture::useWorkerDatabase($database, Wp1RealDriverHarness::WORKER_CONNECTION);

    try {
        Wp2RealDriverHarness::migrateWorkerDatabase();

        config()->set('events.features.commerce.public_paid_checkout_enabled', true);

        $organizer = User::factory()->create();

        // Lean deterministic fixtures through real models and the real
        // package occurrence writer. The full event factory hooks
        // (schedule/classification/link sync, membership, spatie roles)
        // this race only needs a registrable paid event. Full-stack
        // checkout creation stays covered by Wp2LifecycleTest.
        $event = Event::query()->create([
            'title' => 'Paid Prepare Race',
            'slug' => 'paid-prepare-race-'.Str::lower(Str::random(7)),
            'status' => 'approved',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
            'delivery_mode' => 'online',
            'timezone' => 'Asia/Kuala_Lumpur',
            'pricing_mode' => 'paid',
            'registration_mode' => 'required',
            'created_by_type' => $organizer->getMorphClass(),
            'created_by_id' => $organizer->getKey(),
        ]);

        app(SyncPrimaryEventOccurrenceAction::class)->handle($event, [
            'schedule_kind' => ScheduleKind::Single,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHours(2),
            'timezone' => 'Asia/Kuala_Lumpur',
        ]);

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

        $sharer = User::factory()->create(['email' => 'wp2-sharer@example.test']);
        $buyer = User::factory()->create(['email' => 'wp2-buyer@example.test']);
        $pageUrl = route('events.show', $event);

        app(ShareTrackingService::class)->createOrReuseLink($sharer, $pageUrl, 'Majlis', 'web');
        $affiliate = Affiliate::query()
            ->where('owner_type', $sharer->getMorphClass())
            ->where('owner_id', $sharer->getKey())
            ->firstOrFail();
        $link = AffiliateLink::query()->where('affiliate_id', $affiliate->getKey())->firstOrFail();
        $cookie = wp2RaceLanding($pageUrl.'?share='.$link->custom_slug);

        $participants = [[
            'name' => 'Prepare Racer',
            'email' => $buyer->email,
            'phone' => $buyer->phone,
            'is_primary' => true,
            'is_purchaser' => true,
            'participant_type' => $buyer->getMorphClass(),
            'participant_id' => (string) $buyer->getKey(),
        ]];

        [$first, $second] = Wp2RealDriverHarness::runRace([
            'database' => $database,
            'app_url' => (string) config('app.url'),
            'mode' => 'prepare',
            'payload' => [
                'event_id' => (string) $event->getKey(),
                'ticket_id' => (string) $ticket->getKey(),
                'buyer_id' => (string) $buyer->getKey(),
                'key' => 'wp2-prepare-race',
                'quantity' => 1,
                'participants' => $participants,
                'cookie' => $cookie,
            ],
        ]);

        expect((string) $first['session_id'])->toBe((string) $second['session_id'])
            ->and($first['attribution_id'])->not->toBeNull()
            ->and((string) $first['attribution_id'])->toBe((string) $second['attribution_id'])
            ->and(CheckoutSession::query()->count())->toBe(1)
            ->and(CartModel::query()->count())->toBe(1);

        $sessionId = (string) $first['session_id'];
        $originalSnapshot = CheckoutSession::query()->whereKey($sessionId)->firstOrFail()->payment_data;

        $retryRequest = Request::create((string) config('app.url'), 'GET');
        $retryRequest->cookies->set((string) config('dawah-share.cookie.name', 'mi_dawah_share'), (string) $cookie);

        $retry = OwnerContext::withOwner(null, fn (): CheckoutSession => app(PrepareEventCheckoutAction::class)->handle(
            event: $event->fresh(),
            ticketType: TicketType::query()->whereKey($ticket->getKey())->firstOrFail(),
            quantity: 1,
            participants: [[
                'name' => 'Different Context',
                'email' => 'different@example.test',
                'is_primary' => true,
                'is_purchaser' => true,
                'participant_type' => $buyer->getMorphClass(),
                'participant_id' => (string) $buyer->getKey(),
            ]],
            buyer: $buyer->fresh(),
            agreement: ['version' => 'event-participation-v1'],
            idempotencyKey: 'wp2-prepare-race',
            request: $retryRequest,
        ));

        expect((string) $retry->getKey())->toBe($sessionId)
            ->and(CheckoutSession::query()->count())->toBe(1)
            ->and($retry->payment_data)->toBe($originalSnapshot);
    } finally {
        RealDriverFixture::restoreDatabase($previous, Wp1RealDriverHarness::WORKER_CONNECTION);
        RealDriverFixture::dropDatabase($database);
    }
}

$label = 'isolated PostgreSQL';
it("freezes one decision for an attributed-vs-unattributed race on {$label}", function (): void {
    wp2AttributedUnattributedRaceCase($this);
});

it("credits exactly one winner for a different-sharer race on {$label}", function (): void {
    wp2DifferentSharerRaceCase($this);
});

it("serializes concurrent browser selection on {$label}", function (): void {
    wp2ConcurrentSelectionRaceCase($this);
});

it("fences concurrent dispatchers to one projection on {$label}", function (): void {
    wp2DispatchFencingRaceCase($this);
});

it("keeps a frozen outcome vs correction race coherent on {$label}", function (): void {
    wp2CorrectionRaceCase($this);
});

it("keeps navigation from overriding a concurrent landing on {$label}", function (): void {
    wp2NavigationLandingRaceCase($this);
});

it("leaves no eligible descendant after source cancellation wins on {$label}", function (): void {
    wp2InheritCorrectionRaceCase($this);
});

it("serializes same-key checkout prepares to one intent on {$label}", function (): void {
    wp2PrepareRaceCase($this);
});
