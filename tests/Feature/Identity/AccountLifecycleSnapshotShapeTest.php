<?php

use App\Features\Identity\Api\AccountLifecycle;
use App\Features\Reports\Models\Report;
use App\Features\SavedSearches\Models\SavedSearch;
use App\Models\Institution;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\DeletedModels\Models\DeletedModel;

it('stores migrated lifecycle state under contributor keys', function (): void {
    $user = User::factory()->create();
    $institution = Institution::factory()->create();
    $user->dailyPrayerInstitutions()->syncWithPivotValues([$institution->id], ['prayer_type' => 'daily']);

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
        ->and($snapshot)->toHaveKeys(['identity', 'reports', 'saved_searches', 'legacy', 'share_tracking'])
        ->and($snapshot)->not->toHaveKeys(['report_ids', 'handled_report_ids', 'social_accounts'])
        ->and($snapshot['identity']['social_accounts'])->toHaveCount(1)
        ->and($snapshot['identity']['social_accounts'][0]['id'])->toBe($socialAccount->id)
        ->and($snapshot['reports']['report_ids'])->toContain((string) $report->id)
        ->and($snapshot['reports']['handled_report_ids'])->toContain((string) $report->id)
        ->and($snapshot['saved_searches']['records'])->toHaveCount(1)
        ->and($snapshot['saved_searches']['records'][0]['id'])->toBe($savedSearch->id)
        ->and($snapshot['legacy'])->toHaveKey('event_saves')
        ->and(DB::table('user_prayer_institutions')->where('user_id', $userId)->count())->toBe(1);

    User::restoreDeletedUser($userId);

    expect(DB::table('user_prayer_institutions')->where('user_id', $userId)->count())->toBe(1);
});

it('orders tagged contributors by priority and key', function (): void {
    $user = User::factory()->create();

    expect(array_keys(app(AccountLifecycle::class)->snapshot((string) $user->id)))
        ->toBe(['identity', 'reports', 'saved_searches']);
});
