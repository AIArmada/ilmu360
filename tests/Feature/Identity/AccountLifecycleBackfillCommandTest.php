<?php

use App\Features\Identity\Models\SocialAccount;
use App\Features\Reports\Models\Report;
use App\Features\SavedSearches\Models\SavedSearch;
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
    $userId = (string) $user->id;
    $user->delete();

    $deleted = legacyDeletedUserRow($userId);

    $this->artisan('identity:backfill-account-lifecycle-snapshots')
        ->expectsOutputToContain('Rows written:              1')
        ->assertSuccessful();

    $converted = $deleted->fresh()->values;
    $snapshot = $converted['deleted_relations_snapshot'];

    expect($converted)->not->toHaveKey('deleted_affiliate_tracking_snapshot')
        ->and($snapshot)->toHaveKeys(['identity', 'reports', 'saved_searches', 'legacy', 'share_tracking'])
        ->and($snapshot)->not->toHaveKeys(['report_ids', 'handled_report_ids', 'social_accounts'])
        ->and($snapshot['legacy'])->not->toHaveKeys(['report_ids', 'handled_report_ids', 'social_accounts', 'saved_searches', 'share_tracking'])
        ->and($snapshot['reports'])->toHaveKeys(['report_ids', 'handled_report_ids'])
        ->and($snapshot['identity'])->toHaveKey('social_accounts')
        ->and($snapshot['saved_searches'])->toHaveKey('records');

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
        ->and(Report::query()->find($report->id)?->handled_by)->toBe($userId);
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
