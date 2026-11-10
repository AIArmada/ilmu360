<?php

use App\Features\Identity\Api\AccountLifecycle;
use App\Features\Identity\Api\AccountPreferences;
use App\Features\Identity\Api\PrayerType;
use App\Features\Identity\Models\SocialAccount;
use App\Features\Reports\Models\Report;
use App\Features\SavedSearches\Models\SavedSearch;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\DeletedModels\Models\DeletedModel;

it('stores migrated lifecycle state under contributor keys', function (): void {
    $user = User::factory()->create();
    $institution = Institution::factory()->create();
    app(AccountPreferences::class)->syncPrayerInstitutions((string) $user->getKey(), PrayerType::Daily, [$institution->id]);

    $socialAccount = SocialAccount::factory()->create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_id' => 'snapshot-shape-user',
    ]);
    $report = Report::factory()->create([
        'reporter_id' => $user->id,
        'handled_by' => $user->id,
    ]);
    $savedSearch = SavedSearch::factory()->create([
        'user_id' => $user->id,
        'name' => 'Snapshot Shape Search',
    ]);

    $userId = (string) $user->id;
    $user->delete();

    $deleted = DeletedModel::query()
        ->where('key', $userId)
        ->where('model', (new User)->getMorphClass())
        ->firstOrFail();

    $values = $deleted->values;
    $snapshot = $values['deleted_relations_snapshot'];

    expect($values)->not->toHaveKey('deleted_affiliate_tracking_snapshot')
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
        ->and($snapshot['events'])->toHaveKeys([
            'owned_event_ids',
            'submitted_event_ids',
            'event_submission_ids',
            'verified_event_checkin_ids',
            'registration_ids',
            'event_checkin_ids',
            'retained_fact_counts',
        ])
        ->and($snapshot['contributions'])->toHaveKeys(['proposer_ids', 'reviewer_ids'])
        ->and($snapshot['moderation'])->toHaveKey('moderation_review_ids')
        ->and($snapshot['identity']['social_accounts'])->toHaveCount(1)
        ->and($snapshot['identity']['social_accounts'][0]['id'])->toBe($socialAccount->id)
        ->and($snapshot['reports']['report_ids'])->toContain((string) $report->id)
        ->and($snapshot['reports']['handled_report_ids'])->toContain((string) $report->id)
        ->and($snapshot['saved_searches']['records'])->toHaveCount(1)
        ->and($snapshot['saved_searches']['records'][0]['id'])->toBe($savedSearch->id)
        ->and($snapshot['engagement'])->toHaveKeys(['user_venue', 'event_saves', 'event_attendees', 'followings'])
        ->and($snapshot['ai'])->toHaveKey('ai_usage_logs')
        ->and($snapshot['communications'])->toHaveKeys(['notification_setting', 'notification_destinations', 'notification_inboxes'])
        ->and($snapshot['legacy'])->toHaveKeys(['model_has_roles', 'model_has_permissions'])
        ->and(DB::table('user_prayer_institutions')->where('user_id', $userId)->count())->toBe(1);

    User::restoreDeletedUser($userId);

    expect(DB::table('user_prayer_institutions')->where('user_id', $userId)->count())->toBe(1);
});

it('orders tagged contributors by priority and key', function (): void {
    $user = User::factory()->create();

    expect(array_keys(app(AccountLifecycle::class)->snapshot((string) $user->id)))
        ->toBe(['identity', 'reports', 'saved_searches', 'membership', 'directory', 'events', 'contributions', 'moderation', 'engagement', 'ai', 'communications']);
});
