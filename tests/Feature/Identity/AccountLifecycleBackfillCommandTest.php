<?php

use App\Features\Identity\Models\SocialAccount;
use App\Features\Reports\Models\Report;
use App\Features\SavedSearches\Models\SavedSearch;
use App\Models\DonationChannel;
use App\Models\Event;
use App\Models\EventCheckin;
use App\Models\Institution;
use App\Models\MembershipApplication;
use App\Models\Registration;
use App\Models\User;
use Spatie\DeletedModels\Models\DeletedModel;

function legacyDeletedUserRow(string $userId): DeletedModel
{
    $deleted = DeletedModel::query()
        ->where('key', $userId)
        ->where('model', (new User)->getMorphClass())
        ->firstOrFail();

    $values = $deleted->values;
    $snapshot = $values['deleted_relations_snapshot'];

    $legacySnapshot = $snapshot['legacy'];
    $legacySnapshot['report_ids'] = $snapshot['reports']['report_ids'];
    $legacySnapshot['handled_report_ids'] = $snapshot['reports']['handled_report_ids'];
    $legacySnapshot['social_accounts'] = $snapshot['identity']['social_accounts'];
    $legacySnapshot['saved_searches'] = $snapshot['saved_searches']['records'];

    foreach (['institution_members', 'person_members', 'reference_members', 'event_members', 'membership_claim_ids', 'membership_application_reviewer_ids', 'scoped_roles', 'scoped_permissions'] as $key) {
        $legacySnapshot[$key] = $snapshot['membership'][$key];
    }

    foreach (['verified_donation_channel_ids', 'verified_person_ids', 'verified_institution_ids', 'verified_reference_ids', 'verified_venue_ids'] as $key) {
        $legacySnapshot[$key] = $snapshot['directory'][$key];
    }

    foreach (['owned_event_ids', 'submitted_event_ids', 'event_submission_ids', 'verified_event_checkin_ids'] as $key) {
        $legacySnapshot[$key] = $snapshot['events'][$key];
    }

    $legacySnapshot['registrations'] = Registration::query()
        ->whereKey($snapshot['events']['registration_ids'] ?? [])
        ->get()
        ->map->attributesToArray()
        ->all();
    $legacySnapshot['event_checkins'] = EventCheckin::query()
        ->whereKey($snapshot['events']['event_checkin_ids'] ?? [])
        ->get()
        ->map->attributesToArray()
        ->all();

    $legacySnapshot['moderation_review_ids'] = $snapshot['moderation']['moderation_review_ids'];
    $legacySnapshot['contribution_request_proposer_ids'] = $snapshot['contributions']['proposer_ids'];
    $legacySnapshot['contribution_request_reviewer_ids'] = $snapshot['contributions']['reviewer_ids'];

    foreach (['user_venue', 'event_saves', 'event_attendees', 'followings'] as $key) {
        $legacySnapshot[$key] = $snapshot['engagement'][$key];
    }

    $legacySnapshot['ai_usage_logs'] = $snapshot['ai']['ai_usage_logs'];
    $legacySnapshot['notification_setting'] = $snapshot['communications']['notification_setting'];
    $legacySnapshot['notification_destinations'] = $snapshot['communications']['notification_destinations'];
    $legacySnapshot['notification_inboxes'] = $snapshot['communications']['notification_inboxes'];

    $values['deleted_relations_snapshot'] = $legacySnapshot;
    $values['deleted_affiliate_tracking_snapshot'] = $snapshot['share_tracking'];

    $deleted->forceFill(['values' => $values])->save();

    return $deleted->fresh();
}

it('previews legacy snapshot conversion without writing', function (): void {
    $user = User::factory()->create();
    $user->delete();

    $deleted = legacyDeletedUserRow((string) $user->id);
    $before = $deleted->values;

    $this->artisan('identity:backfill-account-lifecycle-snapshots', ['--dry-run' => true])
        ->expectsOutputToContain('Legacy rows convertible:   1')
        ->expectsOutputToContain('DRY RUN — no rows were written.')
        ->assertSuccessful();

    expect($deleted->fresh()->values)->toBe($before);
});

it('converts legacy snapshots into the contributor-keyed shape idempotently', function (): void {
    $user = User::factory()->create();
    $socialAccount = SocialAccount::factory()->create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_id' => 'backfill-restore-user',
    ]);
    $savedSearch = SavedSearch::factory()->create([
        'user_id' => $user->id,
        'name' => 'Backfill Restore Search',
    ]);
    $report = Report::factory()->create([
        'reporter_id' => $user->id,
        'handled_by' => $user->id,
    ]);
    $application = MembershipApplication::factory()->create([
        'applicant_id' => $user->id,
        'reviewer_id' => $user->id,
    ]);
    $registration = Registration::factory()
        ->forRegistrant($user)
        ->create(['event_id' => Event::factory()]);
    $userId = (string) $user->id;
    $user->delete();

    $deleted = legacyDeletedUserRow($userId);

    $this->artisan('identity:backfill-account-lifecycle-snapshots')
        ->expectsOutputToContain('Rows written:              1')
        ->assertSuccessful();

    $converted = $deleted->fresh()->values;
    $snapshot = $converted['deleted_relations_snapshot'];

    expect($converted)->not->toHaveKey('deleted_affiliate_tracking_snapshot')
        ->and($snapshot)->toHaveKeys([
            'identity',
            'reports',
            'saved_searches',
            'membership',
            'directory',
            'events',
            'moderation',
            'contributions',
            'engagement',
            'ai',
            'communications',
            'legacy',
            'share_tracking',
        ])
        ->and($snapshot)->not->toHaveKeys(['report_ids', 'handled_report_ids', 'social_accounts'])
        ->and($snapshot['legacy'])->not->toHaveKeys([
            'report_ids',
            'handled_report_ids',
            'social_accounts',
            'saved_searches',
            'share_tracking',
            'institution_members',
            'person_members',
            'reference_members',
            'event_members',
            'membership_claim_ids',
            'membership_application_reviewer_ids',
            'verified_person_ids',
            'verified_institution_ids',
            'verified_reference_ids',
            'verified_venue_ids',
            'verified_donation_channel_ids',
            'owned_event_ids',
            'submitted_event_ids',
            'event_submission_ids',
            'verified_event_checkin_ids',
            'registrations',
            'event_checkins',
            'moderation_review_ids',
            'contribution_request_proposer_ids',
            'contribution_request_reviewer_ids',
            'user_venue',
            'event_saves',
            'event_attendees',
            'followings',
            'ai_usage_logs',
            'notification_setting',
            'notification_destinations',
            'notification_inboxes',
        ])
        ->and($snapshot['reports'])->toHaveKeys(['report_ids', 'handled_report_ids'])
        ->and($snapshot['identity'])->toHaveKey('social_accounts')
        ->and($snapshot['saved_searches'])->toHaveKey('records')
        ->and($snapshot['membership'])->toHaveKeys(['institution_members', 'person_members', 'reference_members', 'event_members', 'membership_claim_ids', 'membership_application_reviewer_ids'])
        ->and($snapshot['directory'])->toHaveKeys(['verified_donation_channel_ids', 'verified_person_ids', 'verified_institution_ids', 'verified_reference_ids', 'verified_venue_ids'])
        ->and($snapshot['events'])->toHaveKeys(['owned_event_ids', 'submitted_event_ids', 'event_submission_ids', 'verified_event_checkin_ids', 'registrations', 'event_checkins'])
        ->and($snapshot['moderation'])->toHaveKey('moderation_review_ids')
        ->and($snapshot['contributions'])->toHaveKeys(['proposer_ids', 'reviewer_ids'])
        ->and($snapshot['engagement'])->toHaveKeys(['user_venue', 'event_saves', 'event_attendees', 'followings'])
        ->and($snapshot['ai'])->toHaveKey('ai_usage_logs')
        ->and($snapshot['communications'])->toHaveKeys(['notification_setting', 'notification_destinations', 'notification_inboxes']);

    $this->artisan('identity:backfill-account-lifecycle-snapshots')
        ->expectsOutputToContain('Already migrated:          1')
        ->expectsOutputToContain('Rows written:              0')
        ->assertSuccessful();

    expect($deleted->fresh()->values)->toBe($converted);

    User::restoreDeletedUser($userId);

    expect(User::query()->find($userId))->not->toBeNull()
        ->and(SocialAccount::query()->find($socialAccount->id))->not->toBeNull()
        ->and(SavedSearch::query()->find($savedSearch->id))->not->toBeNull()
        ->and(Report::query()->find($report->id)?->reporter_id)->toBe($userId)
        ->and(Report::query()->find($report->id)?->handled_by)->toBe($userId)
        ->and($application->fresh()->applicant_id)->toBe($userId)
        ->and($application->fresh()->reviewer_id)->toBe($userId)
        ->and($registration->fresh()?->isForUser($user))->toBeTrue();
});

it('adds membership and directory keys to snapshots captured before I3', function (): void {
    $user = User::factory()->create();
    $institution = Institution::factory()->create();
    $donationChannel = DonationChannel::factory()->create(['verified_by' => $user->id]);

    $institution->members()->attach($user->id, ['joined_at' => now()]);
    $user->delete();

    $deleted = DeletedModel::query()
        ->where('key', $user->id)
        ->where('model', (new User)->getMorphClass())
        ->firstOrFail();

    $values = $deleted->values;
    $snapshot = $values['deleted_relations_snapshot'];

    $legacy = $snapshot['legacy'];
    foreach (['institution_members', 'person_members', 'reference_members', 'event_members', 'membership_claim_ids', 'membership_application_reviewer_ids', 'scoped_roles', 'scoped_permissions'] as $key) {
        $legacy[$key] = $snapshot['membership'][$key];
    }
    foreach (['verified_donation_channel_ids', 'verified_person_ids', 'verified_institution_ids', 'verified_reference_ids', 'verified_venue_ids'] as $key) {
        $legacy[$key] = $snapshot['directory'][$key];
    }

    unset($snapshot['membership'], $snapshot['directory']);
    $snapshot['legacy'] = $legacy;
    $values['deleted_relations_snapshot'] = $snapshot;
    $deleted->forceFill(['values' => $values])->save();

    $this->artisan('identity:backfill-account-lifecycle-snapshots')
        ->expectsOutputToContain('Rows written:              1')
        ->assertSuccessful();

    $converted = $deleted->fresh()->values['deleted_relations_snapshot'];

    expect($converted['membership']['institution_members'])->toHaveCount(1)
        ->and($converted['membership']['membership_claim_ids'])->toBe([])
        ->and($converted['directory']['verified_donation_channel_ids'])->toBe([(string) $donationChannel->id])
        ->and($converted['legacy'])->not->toHaveKeys(['institution_members', 'verified_donation_channel_ids']);
});

it('adds event, moderation, and contribution keys to snapshots captured before I4', function (): void {
    $user = User::factory()->create();
    $application = MembershipApplication::factory()->create([
        'applicant_id' => $user->id,
        'reviewer_id' => $user->id,
    ]);
    $registration = Registration::factory()
        ->forRegistrant($user)
        ->create(['event_id' => Event::factory()]);

    $user->delete();

    $deleted = DeletedModel::query()
        ->where('key', $user->id)
        ->where('model', (new User)->getMorphClass())
        ->firstOrFail();

    $values = $deleted->values;
    $snapshot = $values['deleted_relations_snapshot'];

    $legacy = $snapshot['legacy'];
    foreach (['owned_event_ids', 'submitted_event_ids', 'event_submission_ids', 'verified_event_checkin_ids'] as $key) {
        $legacy[$key] = $snapshot['events'][$key];
    }
    $legacy['registrations'] = Registration::query()
        ->whereKey($snapshot['events']['registration_ids'])
        ->get()
        ->map->attributesToArray()
        ->all();
    $legacy['event_checkins'] = [];
    $legacy['moderation_review_ids'] = $snapshot['moderation']['moderation_review_ids'];
    $legacy['contribution_request_proposer_ids'] = $snapshot['contributions']['proposer_ids'];
    $legacy['contribution_request_reviewer_ids'] = $snapshot['contributions']['reviewer_ids'];

    unset($snapshot['events'], $snapshot['moderation'], $snapshot['contributions']);
    $snapshot['legacy'] = $legacy;
    $values['deleted_relations_snapshot'] = $snapshot;
    $deleted->forceFill(['values' => $values])->save();

    $this->artisan('identity:backfill-account-lifecycle-snapshots')
        ->expectsOutputToContain('Rows written:              1')
        ->assertSuccessful();

    $converted = $deleted->fresh()->values['deleted_relations_snapshot'];

    expect($converted['events']['owned_event_ids'])->toBe($snapshot['legacy']['owned_event_ids'])
        ->and($converted['events']['registration_ids'])->toBe([(string) $registration->id])
        ->and($converted['events']['registrations'])->toHaveCount(1)
        ->and($converted['moderation']['moderation_review_ids'])->toBe([])
        ->and($converted['contributions']['proposer_ids'])->toBe([])
        ->and($converted['legacy'])->not->toHaveKeys(['owned_event_ids', 'registrations', 'moderation_review_ids', 'contribution_request_proposer_ids']);

    User::restoreDeletedUser($user->id);

    expect($application->fresh()->applicant_id)->toBe($user->id)
        ->and($registration->fresh()?->isForUser($user))->toBeTrue();
});

it('ignores deleted rows from other models', function (): void {
    $other = DeletedModel::create([
        'key' => 'other-key',
        'model' => 'other-model',
        'values' => [
            'deleted_relations_snapshot' => [
                'report_ids' => ['ignored'],
            ],
        ],
    ]);

    $before = $other->values;

    $this->artisan('identity:backfill-account-lifecycle-snapshots')
        ->expectsOutputToContain('Deleted user rows scanned: 0')
        ->assertSuccessful();

    expect($other->fresh()->values)->toBe($before);
});
