<?php

use AIArmada\Affiliates\Enums\CommissionType;
use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateAttribution;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateLink;
use AIArmada\Affiliates\Models\AffiliateTouchpoint;
use AIArmada\Affiliates\States\Active;
use AIArmada\Affiliates\States\PendingConversion;
use AIArmada\FilamentAuthz\Facades\Authz;
use AIArmada\FilamentAuthz\Models\Role;
use App\Filament\Pages\DeletedUsers;
use App\Models\AiUsageLog;
use App\Models\ContributionRequest;
use App\Models\DonationChannel;
use App\Models\Event;
use App\Models\EventCheckin;
use App\Models\EventSubmission;
use App\Models\Institution;
use App\Models\MembershipClaim;
use App\Models\ModerationReview;
use App\Models\NotificationDelivery;
use App\Models\NotificationDestination;
use App\Models\NotificationMessage;
use App\Models\NotificationRule;
use App\Models\NotificationSetting;
use App\Models\PendingNotification;
use App\Models\Reference;
use App\Models\Registration;
use App\Models\Report;
use App\Models\SavedSearch;
use App\Models\SocialAccount;
use App\Models\Speaker;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
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
    $speaker = Speaker::factory()->create();
    $reference = Reference::factory()->create();
    $venue = Venue::factory()->create();
    $followedInstitution = Institution::factory()->create();
    $followedSpeaker = Speaker::factory()->create();
    $followedReference = Reference::factory()->create();
    $ownedEvent = Event::factory()->create([
        'user_id' => $user->id,
    ]);
    $submittedEvent = Event::factory()->create([
        'submitter_id' => $user->id,
    ]);
    $sharedEvent = Event::factory()->create();
    $sharedEvent->update([
        'saves_count' => 99,
        'going_count' => 88,
    ]);
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

    $registration = Registration::factory()->create([
        'event_id' => $sharedEvent->id,
        'user_id' => $user->id,
        'status' => 'registered',
    ]);
    $ownCheckin = EventCheckin::factory()->create([
        'event_id' => $sharedEvent->id,
        'registration_id' => $registration->id,
        'user_id' => $user->id,
        'verified_by_user_id' => $user->id,
    ]);
    $verifiedCheckin = EventCheckin::factory()->create([
        'event_id' => $sharedEvent->id,
        'user_id' => $otherUser->id,
        'verified_by_user_id' => $user->id,
    ]);
    $savedSearch = SavedSearch::factory()->create([
        'user_id' => $user->id,
        'name' => 'Restore Search',
    ]);

    NotificationSetting::factory()->create([
        'user_id' => $user->id,
        'locale' => 'ms',
        'timezone' => 'UTC',
    ]);
    $notificationRule = NotificationRule::factory()->create([
        'user_id' => $user->id,
        'scope_key' => 'restore-rule',
    ]);
    $notificationDestination = NotificationDestination::factory()->create([
        'user_id' => $user->id,
        'address' => 'restore-device-token',
    ]);
    $pendingNotification = PendingNotification::factory()->create([
        'user_id' => $user->id,
        'fingerprint' => 'restore-pending-notification',
    ]);
    $notificationMessage = NotificationMessage::factory()->create([
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->id,
        'fingerprint' => 'restore-notification-message',
    ]);
    $notificationDelivery = NotificationDelivery::factory()->create([
        'notification_message_id' => $pendingNotification->id,
        'user_id' => $user->id,
        'destination_id' => $notificationDestination->id,
        'fingerprint' => 'restore-notification-delivery',
    ]);

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
        'submitted_by' => $user->id,
    ]);
    $contributionRequest = ContributionRequest::factory()->create([
        'proposer_id' => $user->id,
        'reviewer_id' => $user->id,
    ]);
    $membershipClaim = MembershipClaim::factory()->create([
        'claimant_id' => $user->id,
        'reviewer_id' => $user->id,
    ]);
    $moderationReview = ModerationReview::factory()->create([
        'event_id' => $sharedEvent->id,
        'moderator_id' => $user->id,
    ]);
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
        'subject_identifier' => 'restore-me-event',
        'subject_instance' => 'web',
        'subject_title_snapshot' => 'Restore Me Event',
        'subject_metadata' => [
            'source' => 'test',
        ],
        'is_active' => true,
    ]);

    $affiliateAttribution = AffiliateAttribution::query()->create([
        'affiliate_id' => $affiliate->id,
        'affiliate_code' => $affiliate->code,
        'subject_type' => 'event',
        'subject_identifier' => 'restore-me-event',
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
        'subject_identifier' => 'restore-me-event',
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
        'subject_identifier' => 'restore-me-event',
        'subject_instance' => 'web',
        'subject_title_snapshot' => 'Restore Me Event',
        'cart_identifier' => 'cart-restore-me',
        'cart_instance' => 'web',
        'voucher_code' => 'RESTORE-ME',
        'external_reference' => 'order-restore-me',
        'order_reference' => 'order-restore-me',
        'conversion_type' => 'signup',
        'subtotal_minor' => 10000,
        'value_minor' => 10000,
        'total_minor' => 10000,
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
    $user->speakers()->attach($speaker->id, ['joined_at' => $speakerJoinedAt]);
    $user->references()->attach($reference->id, ['joined_at' => $referenceJoinedAt]);
    $user->venues()->attach($venue->id, ['joined_at' => $venueJoinedAt]);

    $user->followingInstitutions()->attach($followedInstitution->id);
    $user->followingSpeakers()->attach($followedSpeaker->id);
    $user->followingReferences()->attach($followedReference->id);
    $user->savedEvents()->attach($sharedEvent->id);
    $user->goingEvents()->attach($sharedEvent->id);
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
    assertDatabaseHas('speakers', ['id' => $speaker->id]);
    assertDatabaseHas('references', ['id' => $reference->id]);
    assertDatabaseHas('venues', ['id' => $venue->id]);
    assertDatabaseHas('events', [
        'id' => $ownedEvent->id,
        'user_id' => null,
    ]);
    assertDatabaseHas('events', [
        'id' => $submittedEvent->id,
        'submitter_id' => null,
    ]);
    assertDatabaseHas('donation_channels', [
        'id' => $donationChannel->id,
        'verified_by' => null,
    ]);
    assertDatabaseHas('event_checkins', [
        'id' => $verifiedCheckin->id,
        'verified_by_user_id' => null,
    ]);

    assertDatabaseMissing('institution_user', [
        'institution_id' => $institution->id,
        'user_id' => $user->id,
    ]);
    assertDatabaseMissing('speaker_user', [
        'speaker_id' => $speaker->id,
        'user_id' => $user->id,
    ]);
    assertDatabaseMissing('reference_user', [
        'reference_id' => $reference->id,
        'user_id' => $user->id,
    ]);
    assertDatabaseMissing('user_venue', [
        'venue_id' => $venue->id,
        'user_id' => $user->id,
    ]);
    assertDatabaseMissing('registrations', ['id' => $registration->id]);
    assertDatabaseMissing('event_checkins', ['id' => $ownCheckin->id]);
    assertDatabaseMissing('saved_searches', ['id' => $savedSearch->id]);
    assertDatabaseMissing('notification_rules', ['id' => $notificationRule->id]);
    assertDatabaseMissing('notification_destinations', ['id' => $notificationDestination->id]);
    assertDatabaseMissing('notification_messages', ['id' => $pendingNotification->id]);
    assertDatabaseMissing('notifications', ['id' => $notificationMessage->id]);
    assertDatabaseMissing('notification_deliveries', ['id' => $notificationDelivery->id]);
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

    assertDatabaseMissing('deleted_models', [
        'key' => $user->id,
        'model' => $user->getMorphClass(),
    ]);

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

    assertDatabaseHas('institution_user', [
        'institution_id' => $institution->id,
        'user_id' => $user->id,
        'joined_at' => $institutionJoinedAt->toDateTimeString(),
    ]);

    assertDatabaseHas('speaker_user', [
        'speaker_id' => $speaker->id,
        'user_id' => $user->id,
        'joined_at' => $speakerJoinedAt->toDateTimeString(),
    ]);

    assertDatabaseHas('reference_user', [
        'reference_id' => $reference->id,
        'user_id' => $user->id,
        'joined_at' => $referenceJoinedAt->toDateTimeString(),
    ]);

    assertDatabaseHas('user_venue', [
        'venue_id' => $venue->id,
        'user_id' => $user->id,
        'joined_at' => $venueJoinedAt->toDateTimeString(),
    ]);

    assertDatabaseHas('followings', [
        'user_id' => $user->id,
        'followable_id' => $followedInstitution->id,
        'followable_type' => $followedInstitution->getMorphClass(),
    ]);

    assertDatabaseHas('followings', [
        'user_id' => $user->id,
        'followable_id' => $followedSpeaker->id,
        'followable_type' => $followedSpeaker->getMorphClass(),
    ]);

    assertDatabaseHas('followings', [
        'user_id' => $user->id,
        'followable_id' => $followedReference->id,
        'followable_type' => $followedReference->getMorphClass(),
    ]);

    assertDatabaseHas('event_saves', [
        'event_id' => $sharedEvent->id,
        'user_id' => $user->id,
    ]);

    assertDatabaseHas('event_attendees', [
        'event_id' => $sharedEvent->id,
        'user_id' => $user->id,
    ]);

    assertDatabaseHas('events', [
        'id' => $sharedEvent->id,
        'saves_count' => 1,
        'going_count' => 1,
    ]);

    assertDatabaseHas('event_user', [
        'event_id' => $sharedEvent->id,
        'user_id' => $user->id,
        'joined_at' => $memberJoinedAt->toDateTimeString(),
    ]);

    assertDatabaseHas('events', [
        'id' => $ownedEvent->id,
        'user_id' => $user->id,
    ]);
    assertDatabaseHas('events', [
        'id' => $submittedEvent->id,
        'submitter_id' => $user->id,
    ]);

    assertDatabaseHas('event_submissions', [
        'id' => $eventSubmission->id,
        'submitted_by' => $user->id,
    ]);
    assertDatabaseHas('contribution_requests', [
        'id' => $contributionRequest->id,
        'proposer_id' => $user->id,
        'reviewer_id' => $user->id,
    ]);
    assertDatabaseHas('membership_claims', [
        'id' => $membershipClaim->id,
        'claimant_id' => $user->id,
        'reviewer_id' => $user->id,
    ]);
    assertDatabaseHas('moderation_reviews', [
        'id' => $moderationReview->id,
        'moderator_id' => $user->id,
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
    assertDatabaseHas('event_checkins', [
        'id' => $verifiedCheckin->id,
        'verified_by_user_id' => $user->id,
    ]);
    assertDatabaseHas('registrations', [
        'id' => $registration->id,
        'user_id' => $user->id,
    ]);
    assertDatabaseHas('event_checkins', [
        'id' => $ownCheckin->id,
        'user_id' => $user->id,
        'verified_by_user_id' => $user->id,
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

    assertDatabaseHas('notification_settings', [
        'user_id' => $user->id,
        'locale' => 'ms',
    ]);
    assertDatabaseHas('notification_rules', [
        'id' => $notificationRule->id,
        'user_id' => $user->id,
    ]);
    assertDatabaseHas('notification_destinations', [
        'id' => $notificationDestination->id,
        'user_id' => $user->id,
    ]);
    assertDatabaseHas('notification_messages', [
        'id' => $pendingNotification->id,
        'user_id' => $user->id,
    ]);
    assertDatabaseHas('notifications', [
        'id' => $notificationMessage->id,
        'notifiable_id' => $user->id,
    ]);
    assertDatabaseHas('notification_deliveries', [
        'id' => $notificationDelivery->id,
        'user_id' => $user->id,
        'destination_id' => $notificationDestination->id,
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
    $speaker = Speaker::factory()->create();
    $reference = Reference::factory()->create();
    $venue = Venue::factory()->create();
    $ownedEvent = Event::factory()->create([
        'user_id' => $user->id,
    ]);
    $submittedEvent = Event::factory()->create([
        'submitter_id' => $user->id,
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
    $user->speakers()->attach($speaker->id, ['joined_at' => $speakerJoinedAt]);
    $user->references()->attach($reference->id, ['joined_at' => $referenceJoinedAt]);
    $user->venues()->attach($venue->id, ['joined_at' => $venueJoinedAt]);
    $user->savedEvents()->attach($sharedEvent->id);
    $user->goingEvents()->attach($sharedEvent->id);
    $user->memberEvents()->attach($sharedEvent->id, ['joined_at' => $eventJoinedAt]);

    $registration = Registration::factory()->create([
        'event_id' => $sharedEvent->id,
        'user_id' => $user->id,
    ]);
    $ownCheckin = EventCheckin::factory()->create([
        'event_id' => $sharedEvent->id,
        'registration_id' => $registration->id,
        'user_id' => $user->id,
    ]);
    $verifiedCheckin = EventCheckin::factory()->create([
        'event_id' => $sharedEvent->id,
        'user_id' => $otherUser->id,
        'verified_by_user_id' => $user->id,
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
    SocialAccount::factory()->create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_id' => 'api-restore-google',
    ]);
    NotificationSetting::factory()->create([
        'user_id' => $user->id,
        'locale' => 'ms',
        'timezone' => 'Asia/Kuala_Lumpur',
    ]);
    $notificationRule = NotificationRule::factory()->create([
        'user_id' => $user->id,
        'scope_key' => 'api-restore-rule',
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
    assertDatabaseHas('events', [
        'id' => $ownedEvent->id,
        'user_id' => null,
    ]);
    assertDatabaseHas('events', [
        'id' => $submittedEvent->id,
        'submitter_id' => null,
    ]);
    assertDatabaseMissing('registrations', ['id' => $registration->id]);
    assertDatabaseMissing('event_checkins', ['id' => $ownCheckin->id]);
    assertDatabaseMissing('saved_searches', ['id' => $savedSearch->id]);
    assertDatabaseMissing('notification_rules', ['id' => $notificationRule->id]);
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

    Livewire::actingAs($superAdmin)
        ->test(DeletedUsers::class)
        ->assertCanSeeTableRecords([$deletedModel])
        ->callTableAction('restore', $deletedModel->getKey())
        ->assertHasNoTableActionErrors();

    assertDatabaseMissing('deleted_models', [
        'key' => $user->id,
        'model' => $user->getMorphClass(),
    ]);

    assertDatabaseHas('users', [
        'id' => $user->id,
        'name' => 'API Restore Target',
        'email' => 'api-restore-target@example.test',
    ]);
    assertDatabaseHas('events', [
        'id' => $ownedEvent->id,
        'user_id' => $user->id,
    ]);
    assertDatabaseHas('events', [
        'id' => $submittedEvent->id,
        'submitter_id' => $user->id,
    ]);
    assertDatabaseHas('institution_user', [
        'institution_id' => $institution->id,
        'user_id' => $user->id,
        'joined_at' => $institutionJoinedAt->toDateTimeString(),
    ]);
    assertDatabaseHas('speaker_user', [
        'speaker_id' => $speaker->id,
        'user_id' => $user->id,
        'joined_at' => $speakerJoinedAt->toDateTimeString(),
    ]);
    assertDatabaseHas('reference_user', [
        'reference_id' => $reference->id,
        'user_id' => $user->id,
        'joined_at' => $referenceJoinedAt->toDateTimeString(),
    ]);
    assertDatabaseHas('user_venue', [
        'venue_id' => $venue->id,
        'user_id' => $user->id,
        'joined_at' => $venueJoinedAt->toDateTimeString(),
    ]);
    assertDatabaseHas('event_saves', [
        'event_id' => $sharedEvent->id,
        'user_id' => $user->id,
    ]);
    assertDatabaseHas('event_attendees', [
        'event_id' => $sharedEvent->id,
        'user_id' => $user->id,
    ]);
    assertDatabaseHas('event_user', [
        'event_id' => $sharedEvent->id,
        'user_id' => $user->id,
        'joined_at' => $eventJoinedAt->toDateTimeString(),
    ]);
    assertDatabaseHas('registrations', [
        'id' => $registration->id,
        'user_id' => $user->id,
    ]);
    assertDatabaseHas('event_checkins', [
        'id' => $ownCheckin->id,
        'user_id' => $user->id,
    ]);
    assertDatabaseHas('event_checkins', [
        'id' => $verifiedCheckin->id,
        'verified_by_user_id' => $user->id,
    ]);
    assertDatabaseHas('saved_searches', [
        'id' => $savedSearch->id,
        'name' => 'API Restore Search',
    ]);
    assertDatabaseHas('donation_channels', [
        'id' => $donationChannel->id,
        'verified_by' => $user->id,
    ]);
    assertDatabaseHas('socialite', [
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_id' => 'api-restore-google',
    ]);
    assertDatabaseHas('notification_settings', [
        'user_id' => $user->id,
        'locale' => 'ms',
        'timezone' => 'Asia/Kuala_Lumpur',
    ]);
    assertDatabaseHas('notification_rules', [
        'id' => $notificationRule->id,
        'user_id' => $user->id,
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
        'user_id' => $deletedUser->id,
    ]);

    $deletedUser->delete();

    $event->forceFill([
        'user_id' => $newOwner->id,
    ])->save();

    User::restoreDeletedUser($deletedUser->id);

    assertDatabaseHas('events', [
        'id' => $event->id,
        'user_id' => $newOwner->id,
    ]);
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
