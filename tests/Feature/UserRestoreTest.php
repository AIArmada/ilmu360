<?php

use AIArmada\Affiliates\Enums\CommissionType;
use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateAttribution;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateLink;
use AIArmada\Affiliates\Models\AffiliateTouchpoint;
use AIArmada\Affiliates\States\Active;
use AIArmada\Affiliates\States\PendingConversion;
use AIArmada\CommerceSupport\Models\Role;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Communications\Enums\NotificationFamily;
use AIArmada\Communications\Enums\NotificationPriority;
use AIArmada\Communications\Enums\NotificationTrigger;
use AIArmada\Communications\Models\CommunicationDestination;
use AIArmada\Communications\Models\CommunicationPreference;
use AIArmada\Communications\Models\NotificationInbox;
use AIArmada\Engagement\Contracts\EngagementCounterService;
use AIArmada\Engagement\Contracts\EngagementManager;
use AIArmada\Engagement\Models\Follow;
use AIArmada\FilamentAuthz\Facades\Authz;
use App\Filament\Pages\DeletedUsers;
use App\Models\AiUsageLog;
use App\Models\ContributionRequest;
use App\Models\DonationChannel;
use App\Models\Event;
use App\Models\EventCheckin;
use App\Models\EventSubmission;
use App\Models\Institution;
use App\Models\MembershipApplication;
use App\Models\ModerationReview;
use App\Models\Person;
use App\Models\Reference;
use App\Models\Registration;
use App\Models\Report;
use App\Models\SavedSearch;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\DeletedModels\Models\DeletedModel;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('restores a deleted user together with key relationships and child records', function () {
    $user = User::factory()->create([
        'name' => 'Restore Me',
        'email' => 'restore-me@example.test',
        'phone' => '+60120000000',
    ]);

    $institution = Institution::factory()->create();
    $person = Person::factory()->create();
    $reference = Reference::factory()->create();
    $venue = Venue::factory()->create();
    $followedInstitution = Institution::factory()->create();
    $followedPerson = Person::factory()->create();
    $followedReference = Reference::factory()->create();
    $ownedEvent = Event::factory()->create([
        'owner_type' => $user->getMorphClass(),
        'owner_id' => $user->id,
    ]);
    $submittedEvent = Event::factory()->create([
        'owner_type' => $user->getMorphClass(),
        'owner_id' => $user->id,
    ]);
    $sharedEvent = Event::factory()->create();
    $otherUser = User::factory()->create();

    Authz::withScope(null, function () use ($user): void {
        $role = Role::findOrCreate('restore-member-role', 'web');

        $user->assignRole($role);
    });

    $modelHasRolesTable = (string) config('permission.table_names.model_has_roles');
    $modelMorphKey = (string) config('permission.column_names.model_morph_key');

    $rolePivot = DB::table($modelHasRolesTable)
        ->where('model_type', $user->getMorphClass())
        ->where($modelMorphKey, $user->id)
        ->first();

    expect($rolePivot)->not->toBeNull();

    SocialAccount::factory()->create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_id' => 'google-restore-user',
    ]);

    $registration = Registration::factory()
        ->forRegistrant($user)
        ->create([
            'event_id' => $sharedEvent->id,
            'status' => 'confirmed',
        ]);
    $ownCheckin = EventCheckin::factory()->create([
        'event_id' => $sharedEvent->id,
    ]);
    $verifiedCheckin = EventCheckin::factory()->create([
        'event_id' => $sharedEvent->id,
    ]);
    $savedSearch = SavedSearch::factory()->create([
        'user_id' => $user->id,
        'name' => 'Restore Search',
    ]);

    $notificationSetting = CommunicationPreference::create([
        'recipient_type' => $user->getMorphClass(),
        'recipient_id' => $user->id,
        'channel' => null,
        'category' => null,
        'locale' => 'ms',
        'timezone' => 'UTC',
    ]);
    $scopedPreference = CommunicationPreference::create([
        'recipient_type' => $user->getMorphClass(),
        'recipient_id' => $user->id,
        'channel' => 'email',
        'category' => 'event_updates',
        'scope_type' => 'topic',
        'scope_key' => 'restore-rule',
        'enabled_at' => now(),
    ]);
    $notificationDestination = OwnerContext::withOwner(null, fn () => CommunicationDestination::query()->create([
        'recipient_type' => $user->getMorphClass(),
        'recipient_id' => $user->id,
        'channel' => 'push',
        'address' => 'restore-device-token',
        'status' => 'active',
        'is_primary' => true,
    ]));
    $notificationMessage = OwnerContext::withOwner(null, fn () => NotificationInbox::query()->create([
        'recipient_type' => $user->getMorphClass(),
        'recipient_id' => $user->id,
        'family' => NotificationFamily::EventUpdate->value,
        'priority' => NotificationPriority::Normal->value,
        'trigger' => NotificationTrigger::EventCancelled->value,
        'title' => 'Restore notification',
        'body' => 'Restore body',
        'data' => [
            'channels_attempted' => [],
            'meta' => [],
            'action_url' => null,
            'entity_type' => null,
            'entity_id' => null,
        ],
        'read_at' => null,
    ]));

    $aiUsageLog = AiUsageLog::query()->create([
        'invocation_id' => (string) Str::uuid(),
        'operation' => 'restore-test',
        'provider' => 'openai',
        'model' => 'gpt-test',
        'input_tokens' => 10,
        'output_tokens' => 20,
        'total_tokens' => 30,
        'cost_usd' => 0.01000000,
        'currency' => 'USD',
        'user_id' => $user->id,
        'meta' => [
            'source' => 'restore-test',
        ],
    ]);

    $eventSubmission = EventSubmission::factory()->create([
        'event_id' => $sharedEvent->id,
        'submitter_type' => $user->getMorphClass(),
        'submitter_id' => $user->id,
    ]);
    $contributionRequest = ContributionRequest::factory()->create([
        'proposer_id' => $user->id,
        'reviewer_id' => $user->id,
    ]);
    $membershipClaim = MembershipApplication::factory()->create([
        'applicant_id' => $user->id,
        'reviewer_id' => $user->id,
    ]);
    $moderationReview = OwnerContext::withOwner(null, fn () => ModerationReview::factory()->create([
        'actionable_type' => Event::class,
        'actionable_id' => $sharedEvent->id,
        'actioned_by_type' => User::class,
        'actioned_by_id' => $user->id,
    ]));
    $report = Report::factory()->create([
        'reporter_id' => $user->id,
        'handled_by' => $user->id,
    ]);
    $donationChannel = DonationChannel::factory()->create([
        'verified_by' => $user->id,
        'status' => 'verified',
        'verified_at' => now(),
    ]);

    $affiliate = new Affiliate([
        'code' => 'restore-me-affiliate',
        'name' => 'Restore Me Affiliate',
        'status' => Active::class,
        'commission_type' => CommissionType::Percentage->value,
        'commission_rate' => 1500,
        'currency' => 'MYR',
        'metadata' => [
            'tracking_case' => 'restore-test',
        ],
        'activated_at' => now(),
    ]);

    $affiliate->assignOwner($user);
    $affiliate->save();

    $affiliateLink = AffiliateLink::query()->create([
        'affiliate_id' => $affiliate->id,
        'destination_url' => 'https://ilmu360.test/events/restore-me',
        'tracking_url' => 'https://ilmu360.test/r/restore-me',
        'short_url' => 'https://ilmu360.test/s/restore-me',
        'custom_slug' => 'restore-me',
        'campaign' => 'restore-user',
        'subject_type' => 'event',
        'subject_key' => 'restore-me-event',
        'subject_instance' => 'web',
        'subject_title_snapshot' => 'Restore Me Event',
        'subject_metadata' => [
            'source' => 'test',
        ],
    ]);

    $affiliateAttribution = AffiliateAttribution::query()->create([
        'affiliate_id' => $affiliate->id,
        'affiliate_code' => $affiliate->code,
        'subject_type' => 'event',
        'subject_key' => 'restore-me-event',
        'subject_instance' => 'web',
        'subject_title_snapshot' => 'Restore Me Event',
        'cart_identifier' => 'cart-restore-me',
        'cart_instance' => 'web',
        'cookie_value' => 'cookie-restore-me',
        'source' => 'web',
        'medium' => 'social',
        'campaign' => 'restore-user',
        'landing_url' => 'https://ilmu360.test/events/restore-me',
        'referrer_url' => 'https://example.com',
        'user_agent' => 'Pest',
        'ip_address' => '127.0.0.1',
        'metadata' => [
            'share_provider' => 'web',
        ],
        'first_seen_at' => now()->subDay(),
        'last_seen_at' => now(),
        'last_cookie_seen_at' => now(),
        'expires_at' => now()->addDay(),
    ]);

    $affiliateTouchpoint = AffiliateTouchpoint::query()->create([
        'affiliate_attribution_id' => $affiliateAttribution->id,
        'affiliate_id' => $affiliate->id,
        'affiliate_code' => $affiliate->code,
        'subject_type' => 'event',
        'subject_key' => 'restore-me-event',
        'subject_instance' => 'web',
        'subject_title_snapshot' => 'Restore Me Event',
        'source' => 'web',
        'medium' => 'social',
        'campaign' => 'restore-user',
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Pest',
        'metadata' => [
            'share_provider' => 'web',
        ],
        'touched_at' => now(),
    ]);

    $affiliateConversion = AffiliateConversion::query()->create([
        'affiliate_id' => $affiliate->id,
        'affiliate_attribution_id' => $affiliateAttribution->id,
        'affiliate_code' => $affiliate->code,
        'subject_type' => 'event',
        'subject_key' => 'restore-me-event',
        'subject_instance' => 'web',
        'subject_title_snapshot' => 'Restore Me Event',
        'voucher_code' => 'RESTORE-ME',
        'external_reference' => 'order-restore-me',
        'conversion_type' => 'signup',
        'subtotal_minor' => 10000,
        'value_minor' => 10000,
        'commission_minor' => 1500,
        'commission_currency' => 'MYR',
        'status' => PendingConversion::class,
        'channel' => 'web',
        'metadata' => [
            'share_provider' => 'web',
            'affiliate_link_id' => $affiliateLink->id,
        ],
        'occurred_at' => now(),
    ]);

    $institutionJoinedAt = now()->subDays(5)->startOfSecond();
    $speakerJoinedAt = now()->subDays(4)->startOfSecond();
    $referenceJoinedAt = now()->subDays(3)->startOfSecond();
    $venueJoinedAt = now()->subDays(3)->startOfSecond();
    $memberJoinedAt = now()->subDays(2)->startOfSecond();

    $user->institutions()->attach($institution->id, ['joined_at' => $institutionJoinedAt]);
    $user->speakers()->attach($person->id, ['joined_at' => $speakerJoinedAt]);
    $user->references()->attach($reference->id, ['joined_at' => $referenceJoinedAt]);
    $user->follow($venue, ['followed_at' => $venueJoinedAt]);

    OwnerContext::withOwner(null, function () use ($user, $followedInstitution): void {
        Follow::query()->create([
            'follower_type' => $user->getMorphClass(),
            'follower_id' => $user->getKey(),
            'followable_type' => $followedInstitution->getMorphClass(),
            'followable_id' => $followedInstitution->getKey(),
            'status' => 'active',
            'followed_at' => now(),
        ]);
    });
    OwnerContext::withOwner(null, function () use ($user, $followedPerson): void {
        Follow::query()->create([
            'follower_type' => $user->getMorphClass(),
            'follower_id' => $user->getKey(),
            'followable_type' => $followedPerson->getMorphClass(),
            'followable_id' => $followedPerson->getKey(),
            'status' => 'active',
            'followed_at' => now(),
        ]);
    });
    OwnerContext::withOwner(null, function () use ($user, $followedReference): void {
        Follow::query()->create([
            'follower_type' => $user->getMorphClass(),
            'follower_id' => $user->getKey(),
            'followable_type' => $followedReference->getMorphClass(),
            'followable_id' => $followedReference->getKey(),
            'status' => 'active',
            'followed_at' => now(),
        ]);
    });
    app(EngagementManager::class)->bookmark($user, $sharedEvent);
    $user->respond($sharedEvent, 'going');
    $user->memberEvents()->attach($sharedEvent->id, ['joined_at' => $memberJoinedAt]);

    $user->delete();

    assertDatabaseHas('deleted_models', [
        'key' => $user->id,
        'model' => $user->getMorphClass(),
    ]);

    $deletedModel = DeletedModel::query()
        ->where('key', $user->id)
        ->where('model', $user->getMorphClass())
        ->firstOrFail();

    expect($deletedModel->values)
        ->not->toHaveKey('password')
        ->not->toHaveKey('remember_token');

    assertDatabaseHas('institutions', ['id' => $institution->id]);
    assertDatabaseHas('speakers', ['id' => $person->id]);
    assertDatabaseHas('references', ['id' => $reference->id]);
    assertDatabaseHas('venues', ['id' => $venue->id]);
    expect($ownedEvent->fresh()->owner_id)->toBeNull()
        ->and($submittedEvent->fresh()->owner_id)->toBeNull();
    assertDatabaseHas('donation_channels', [
        'id' => $donationChannel->id,
        'verified_by' => null,
    ]);
    assertDatabaseHas((new EventCheckin)->getTable(), [
        'id' => $verifiedCheckin->id,
        'verified_by_user_id' => null,
    ]);

    assertDatabaseMissing($institution->members()->getTable(), [
        'institution_id' => $institution->id,
        'user_id' => $user->id,
    ]);
    assertDatabaseMissing($person->members()->getTable(), [
        'person_id' => $person->id,
        'user_id' => $user->id,
    ]);
    assertDatabaseMissing($reference->members()->getTable(), [
        'reference_id' => $reference->id,
        'user_id' => $user->id,
    ]);
    assertDatabaseMissing((new Follow)->getTable(), [
        'followable_id' => $venue->id,
        'follower_id' => $user->id,
    ]);
    expect(Registration::query()->whereKey($registration->id)->exists())->toBeFalse();
    assertDatabaseHas((new EventCheckin)->getTable(), ['id' => $ownCheckin->id]);
    assertDatabaseMissing('saved_searches', ['id' => $savedSearch->id]);
    assertDatabaseHas('communication_preferences', ['id' => $scopedPreference->id]);
    assertDatabaseMissing('communication_destinations', ['id' => $notificationDestination->id]);
    assertDatabaseMissing('notification_inboxes', ['id' => $notificationMessage->id]);
    assertDatabaseMissing('ai_usage_logs', ['id' => $aiUsageLog->id]);
    assertDatabaseMissing($modelHasRolesTable, [
        $modelMorphKey => $user->id,
        'model_type' => $user->getMorphClass(),
    ]);

    assertDatabaseMissing($affiliate->getTable(), [
        'id' => $affiliate->id,
    ]);

    assertDatabaseMissing($affiliateLink->getTable(), [
        'id' => $affiliateLink->id,
    ]);

    assertDatabaseMissing($affiliateAttribution->getTable(), [
        'id' => $affiliateAttribution->id,
    ]);

    assertDatabaseMissing($affiliateTouchpoint->getTable(), [
        'id' => $affiliateTouchpoint->id,
    ]);

    assertDatabaseMissing($affiliateConversion->getTable(), [
        'id' => $affiliateConversion->id,
    ]);

    assertDatabaseMissing($affiliate->getTable(), [
        'id' => $affiliate->id,
    ]);

    $restoredUser = User::restoreDeletedUser($user->id);

    expect($restoredUser->exists)->toBeTrue();

    assertDatabaseHas('users', [
        'id' => $user->id,
        'name' => 'Restore Me',
        'email' => 'restore-me@example.test',
    ]);

    assertDatabaseHas($affiliate->getTable(), [
        'id' => $affiliate->id,
        'owner_type' => $user->getMorphClass(),
        'owner_id' => $user->id,
    ]);

    assertDatabaseHas($institution->members()->getTable(), [
        'institution_id' => $institution->id,
        'user_id' => $user->id,
        'joined_at' => $institutionJoinedAt->toDateTimeString(),
    ]);

    assertDatabaseHas($person->members()->getTable(), [
        'person_id' => $person->id,
        'user_id' => $user->id,
        'joined_at' => $speakerJoinedAt->toDateTimeString(),
    ]);

    assertDatabaseHas($reference->members()->getTable(), [
        'reference_id' => $reference->id,
        'user_id' => $user->id,
        'joined_at' => $referenceJoinedAt->toDateTimeString(),
    ]);

    assertDatabaseHas((new Follow)->getTable(), [
        'followable_id' => $venue->id,
        'follower_id' => $user->id,
    ]);

    assertDatabaseHas('engagement_follows', [
        'follower_id' => $user->id,
        'follower_type' => (new User)->getMorphClass(),
        'status' => 'active',
        'followable_id' => $followedInstitution->id,
        'followable_type' => $followedInstitution->getMorphClass(),
    ]);

    assertDatabaseHas('engagement_follows', [
        'follower_id' => $user->id,
        'follower_type' => (new User)->getMorphClass(),
        'status' => 'active',
        'followable_id' => $followedPerson->id,
        'followable_type' => $followedPerson->getMorphClass(),
    ]);

    assertDatabaseHas('engagement_follows', [
        'follower_id' => $user->id,
        'follower_type' => (new User)->getMorphClass(),
        'status' => 'active',
        'followable_id' => $followedReference->id,
        'followable_type' => $followedReference->getMorphClass(),
    ]);

    assertDatabaseHas('engagement_bookmarks', [
        'bookmarkable_type' => $sharedEvent->getMorphClass(),
        'bookmarkable_id' => $sharedEvent->id,
        'bookmarker_type' => $user->getMorphClass(),
        'bookmarker_id' => $user->id,
        'status' => 'active',
    ]);

    assertDatabaseHas(config('engagement.database.tables.responses'), [
        'respondable_id' => $sharedEvent->id,
        'responder_id' => $user->id,
        'response_type' => 'going',
    ]);

    expect(app(EngagementCounterService::class)->countBookmarks($sharedEvent))->toBe(1)
        ->and(app(EngagementCounterService::class)->countResponses($sharedEvent, 'going'))->toBe(1);

    assertDatabaseHas('event_members', [
        'event_id' => $sharedEvent->id,
        'user_id' => $user->id,
        'joined_at' => $memberJoinedAt->toDateTimeString(),
    ]);

    expect($ownedEvent->fresh()->owner_id)->toBe($user->id)
        ->and($submittedEvent->fresh()->owner_id)->toBe($user->id);

    expect($eventSubmission->fresh()->submitter_id)->toBe($user->id);
    assertDatabaseHas('contribution_requests', [
        'id' => $contributionRequest->id,
        'proposer_id' => $user->id,
        'reviewer_id' => $user->id,
    ]);
    assertDatabaseHas('membership_applications', [
        'id' => $membershipClaim->id,
        'reviewer_id' => $user->id,
    ]);
    assertDatabaseHas('moderation_actions', [
        'id' => $moderationReview->id,
        'actioned_by_id' => $user->id,
    ]);
    assertDatabaseHas('reports', [
        'id' => $report->id,
        'reporter_id' => $user->id,
        'handled_by' => $user->id,
    ]);
    assertDatabaseHas('donation_channels', [
        'id' => $donationChannel->id,
        'verified_by' => $user->id,
    ]);
    assertDatabaseHas((new EventCheckin)->getTable(), [
        'id' => $verifiedCheckin->id,
    ]);
    $restoredRegistration = Registration::query()->find($registration->id);

    expect($restoredRegistration)->not->toBeNull()
        ->and($restoredRegistration?->isForUser($user))->toBeTrue();
    assertDatabaseHas((new EventCheckin)->getTable(), [
        'id' => $ownCheckin->id,
    ]);
    assertDatabaseHas('saved_searches', [
        'id' => $savedSearch->id,
        'user_id' => $user->id,
        'name' => 'Restore Search',
    ]);

    assertDatabaseHas('socialite', [
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_id' => 'google-restore-user',
    ]);

    assertDatabaseHas('communication_preferences', [
        'id' => $notificationSetting->id,
        'recipient_id' => $user->id,
        'locale' => 'ms',
    ]);
    assertDatabaseHas('communication_preferences', [
        'id' => $scopedPreference->id,
        'recipient_id' => $user->id,
        'scope_key' => 'restore-rule',
    ]);
    assertDatabaseHas('communication_destinations', [
        'id' => $notificationDestination->id,
        'recipient_id' => $user->id,
    ]);
    assertDatabaseHas('notification_inboxes', [
        'id' => $notificationMessage->id,
        'recipient_id' => $user->id,
    ]);
    assertDatabaseHas('ai_usage_logs', [
        'id' => $aiUsageLog->id,
        'user_id' => $user->id,
        'operation' => 'restore-test',
    ]);
    assertDatabaseHas($modelHasRolesTable, [
        $modelMorphKey => $user->id,
        'model_type' => $user->getMorphClass(),
    ]);

    assertDatabaseHas($affiliate->getTable(), [
        'id' => $affiliate->id,
        'code' => 'restore-me-affiliate',
        'name' => 'Restore Me Affiliate',
    ]);

    assertDatabaseHas($affiliateLink->getTable(), [
        'id' => $affiliateLink->id,
        'affiliate_id' => $affiliate->id,
    ]);

    assertDatabaseHas($affiliateAttribution->getTable(), [
        'id' => $affiliateAttribution->id,
        'affiliate_id' => $affiliate->id,
    ]);

    assertDatabaseHas($affiliateTouchpoint->getTable(), [
        'id' => $affiliateTouchpoint->id,
        'affiliate_id' => $affiliate->id,
        'affiliate_attribution_id' => $affiliateAttribution->id,
    ]);

    assertDatabaseHas($affiliateConversion->getTable(), [
        'id' => $affiliateConversion->id,
        'affiliate_id' => $affiliate->id,
        'affiliate_attribution_id' => $affiliateAttribution->id,
    ]);
});

it('does not let non super admins access the deleted users restore page', function (): void {
    $admin = User::factory()->create();
    $superAdmin = User::factory()->create();

    Authz::withScope(null, function () use ($admin, $superAdmin): void {
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('super_admin', 'web');

        $admin->assignRole('admin');
        $superAdmin->assignRole('super_admin');
    });

    $this->actingAs($admin);

    expect(DeletedUsers::canAccess())->toBeFalse();

    $this->actingAs($superAdmin);

    expect(DeletedUsers::canAccess())->toBeTrue();
});

it('restores an api self-deleted user from the deleted users admin page', function (): void {
    $superAdmin = User::factory()->create();
    $user = User::factory()->create([
        'name' => 'API Restore Target',
        'email' => 'api-restore-target@example.test',
        'password' => 'password',
        'remember_token' => 'restore-token-secret',
    ]);
    $institution = Institution::factory()->create();
    $person = Person::factory()->create();
    $reference = Reference::factory()->create();
    $venue = Venue::factory()->create();
    $ownedEvent = Event::factory()->create([
        'owner_type' => $user->getMorphClass(),
        'owner_id' => $user->id,
    ]);
    $submittedEvent = Event::factory()->create([
        'owner_type' => $user->getMorphClass(),
        'owner_id' => $user->id,
    ]);
    $sharedEvent = Event::factory()->create();
    $otherUser = User::factory()->create();

    Authz::withScope(null, function () use ($superAdmin, $user): void {
        Role::findOrCreate('super_admin', 'web');
        Role::findOrCreate('api-restore-member', 'web');

        $superAdmin->assignRole('super_admin');
        $user->assignRole('api-restore-member');
    });

    $modelHasRolesTable = (string) config('permission.table_names.model_has_roles');
    $modelMorphKey = (string) config('permission.column_names.model_morph_key');

    $institutionJoinedAt = now()->subDays(3)->startOfSecond();
    $speakerJoinedAt = now()->subDays(2)->startOfSecond();
    $referenceJoinedAt = now()->subDay()->startOfSecond();
    $venueJoinedAt = now()->subHours(18)->startOfSecond();
    $eventJoinedAt = now()->subHours(12)->startOfSecond();

    $user->institutions()->attach($institution->id, ['joined_at' => $institutionJoinedAt]);
    $user->speakers()->attach($person->id, ['joined_at' => $speakerJoinedAt]);
    $user->references()->attach($reference->id, ['joined_at' => $referenceJoinedAt]);
    $user->follow($venue, ['followed_at' => $venueJoinedAt]);
    app(EngagementManager::class)->bookmark($user, $sharedEvent);
    $user->respond($sharedEvent, 'going');
    $user->memberEvents()->attach($sharedEvent->id, ['joined_at' => $eventJoinedAt]);

    $registration = Registration::factory()
        ->forRegistrant($user)
        ->create([
            'event_id' => $sharedEvent->id,
        ]);
    $ownCheckin = EventCheckin::factory()->create([
        'event_id' => $sharedEvent->id,
    ]);
    $verifiedCheckin = EventCheckin::factory()->create([
        'event_id' => $sharedEvent->id,
    ]);
    $savedSearch = SavedSearch::factory()->create([
        'user_id' => $user->id,
        'name' => 'API Restore Search',
    ]);
    $donationChannel = DonationChannel::factory()->create([
        'verified_by' => $user->id,
        'status' => 'verified',
        'verified_at' => now(),
    ]);
    $apiNotificationSetting = CommunicationPreference::create([
        'recipient_type' => $user->getMorphClass(),
        'recipient_id' => $user->id,
        'channel' => null,
        'category' => null,
        'locale' => 'ms',
        'timezone' => 'Asia/Kuala_Lumpur',
    ]);
    $apiScopedPreference = CommunicationPreference::create([
        'recipient_type' => $user->getMorphClass(),
        'recipient_id' => $user->id,
        'channel' => 'email',
        'category' => 'event_updates',
        'scope_type' => 'topic',
        'scope_key' => 'api-restore-rule',
        'enabled_at' => now(),
    ]);

    $plainTextToken = $user->createToken('restore-flow-token')->plainTextToken;

    $this->withToken($plainTextToken)
        ->deleteJson(route('api.user.destroy'))
        ->assertOk()
        ->assertJsonPath('message', 'Account deleted successfully.');

    assertDatabaseMissing('users', ['id' => $user->id]);
    assertDatabaseMissing('personal_access_tokens', [
        'tokenable_type' => User::class,
        'tokenable_id' => $user->id,
    ]);
    expect($ownedEvent->fresh()->owner_id)->toBeNull()
        ->and($submittedEvent->fresh()->owner_id)->toBeNull();
    expect(Registration::query()->whereKey($registration->id)->exists())->toBeFalse();
    assertDatabaseHas((new EventCheckin)->getTable(), ['id' => $ownCheckin->id]);
    assertDatabaseMissing('saved_searches', ['id' => $savedSearch->id]);
    assertDatabaseHas('communication_preferences', ['id' => $apiScopedPreference->id]);
    assertDatabaseMissing($modelHasRolesTable, [
        $modelMorphKey => $user->id,
        'model_type' => $user->getMorphClass(),
    ]);

    $deletedModel = DeletedModel::query()
        ->where('key', $user->id)
        ->where('model', $user->getMorphClass())
        ->firstOrFail();

    expect($deletedModel->values)
        ->toMatchArray([
            'name' => 'API Restore Target',
            'email' => 'api-restore-target@example.test',
        ])
        ->not->toHaveKey('password')
        ->not->toHaveKey('remember_token');

    $restoredUser = User::restoreDeletedUser($deletedModel->key);

    expect($restoredUser->getKey())->toBe($user->getKey());

    assertDatabaseHas('users', [
        'id' => $user->id,
        'name' => 'API Restore Target',
        'email' => 'api-restore-target@example.test',
    ]);
    expect($ownedEvent->fresh()->owner_id)->toBe($user->id)
        ->and($submittedEvent->fresh()->owner_id)->toBe($user->id);
    assertDatabaseHas($institution->members()->getTable(), [
        'institution_id' => $institution->id,
        'user_id' => $user->id,
        'joined_at' => $institutionJoinedAt->toDateTimeString(),
    ]);
    assertDatabaseHas($person->members()->getTable(), [
        'person_id' => $person->id,
        'user_id' => $user->id,
        'joined_at' => $speakerJoinedAt->toDateTimeString(),
    ]);
    assertDatabaseHas($reference->members()->getTable(), [
        'reference_id' => $reference->id,
        'user_id' => $user->id,
        'joined_at' => $referenceJoinedAt->toDateTimeString(),
    ]);
    assertDatabaseHas((new Follow)->getTable(), [
        'followable_id' => $venue->id,
        'follower_id' => $user->id,
    ]);
    assertDatabaseHas('engagement_bookmarks', [
        'bookmarkable_type' => $sharedEvent->getMorphClass(),
        'bookmarkable_id' => $sharedEvent->id,
        'bookmarker_type' => $user->getMorphClass(),
        'bookmarker_id' => $user->id,
        'status' => 'active',
    ]);
    assertDatabaseHas(config('engagement.database.tables.responses'), [
        'respondable_id' => $sharedEvent->id,
        'responder_id' => $user->id,
        'response_type' => 'going',
    ]);
    assertDatabaseHas('event_members', [
        'event_id' => $sharedEvent->id,
        'user_id' => $user->id,
        'joined_at' => $eventJoinedAt->toDateTimeString(),
    ]);
    $restoredRegistration = Registration::query()->find($registration->id);

    expect($restoredRegistration)->not->toBeNull()
        ->and($restoredRegistration?->isForUser($user))->toBeTrue();
    assertDatabaseHas((new EventCheckin)->getTable(), [
        'id' => $ownCheckin->id,
    ]);
    assertDatabaseHas((new EventCheckin)->getTable(), [
        'id' => $verifiedCheckin->id,
    ]);
    assertDatabaseHas('saved_searches', [
        'id' => $savedSearch->id,
        'name' => 'API Restore Search',
    ]);
    assertDatabaseHas('donation_channels', [
        'id' => $donationChannel->id,
        'verified_by' => $user->id,
    ]);
    assertDatabaseHas('communication_preferences', [
        'id' => $apiNotificationSetting->id,
        'recipient_id' => $user->id,
        'locale' => 'ms',
        'timezone' => 'Asia/Kuala_Lumpur',
    ]);
    assertDatabaseHas('communication_preferences', [
        'id' => $apiScopedPreference->id,
        'recipient_id' => $user->id,
        'scope_key' => 'api-restore-rule',
    ]);
    assertDatabaseHas($modelHasRolesTable, [
        $modelMorphKey => $user->id,
        'model_type' => $user->getMorphClass(),
    ]);
    assertDatabaseMissing('personal_access_tokens', [
        'tokenable_type' => User::class,
        'tokenable_id' => $user->id,
    ]);
});

it('does not overwrite records reassigned after the user was deleted', function (): void {
    $deletedUser = User::factory()->create();
    $newOwner = User::factory()->create();
    $event = Event::factory()->create([
        'owner_type' => $deletedUser->getMorphClass(),
        'owner_id' => $deletedUser->id,
    ]);

    $deletedUser->delete();

    $event = $event->fresh();
    $event->forceFill([
        'owner_type' => $newOwner->getMorphClass(),
        'owner_id' => $newOwner->id,
    ])->saveQuietly();

    User::restoreDeletedUser($deletedUser->id);

    expect($event->fresh()->owner_id)->toBe($newOwner->id)
        ->and($event->fresh()->owner_type)->toBe($newOwner->getMorphClass());
});

it('rolls back the user restore when restoring a related snapshot fails', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $socialAccount = SocialAccount::factory()->create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_id' => 'rollback-original',
    ]);

    $user->delete();

    DB::table('socialite')->insert([
        'id' => $socialAccount->id,
        'user_id' => $otherUser->id,
        'provider' => 'github',
        'provider_id' => 'rollback-conflict',
        'avatar_url' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => User::restoreDeletedUser($user->id))->toThrow(QueryException::class);

    assertDatabaseMissing('users', [
        'id' => $user->id,
    ]);

    assertDatabaseHas('deleted_models', [
        'key' => $user->id,
        'model' => $user->getMorphClass(),
    ]);
});
