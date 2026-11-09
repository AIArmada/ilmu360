<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Spatie\DeletedModels\Models\DeletedModel;

#[Description('Convert legacy deleted-user relation snapshots into the contributor-keyed account lifecycle shape')]
#[Signature('identity:backfill-account-lifecycle-snapshots
                            {--dry-run : Report the rows that would be rewritten without saving}
                            {--chunk=100 : Number of deleted-user rows to inspect per chunk}')]
class BackfillAccountLifecycleSnapshots extends Command
{
    /**
     * @var array<int, string>
     */
    private const array LEGACY_TOP_LEVEL_KEYS = [
        'report_ids',
        'handled_report_ids',
        'social_accounts',
        'saved_searches',
    ];

    /**
     * @var array<int, string>
     */
    private const array LEGACY_DETECTION_KEYS = [
        'report_ids',
        'handled_report_ids',
        'social_accounts',
    ];

    public function handle(): int
    {
        $model = (new User)->getMorphClass();
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(1, (int) $this->option('chunk'));

        $scanned = 0;
        $convertible = 0;
        $alreadyMigrated = 0;
        $skipped = 0;
        $written = 0;

        DeletedModel::query()
            ->where('model', $model)
            ->orderBy('id')
            ->chunkById($chunk, function ($records) use (
                $dryRun,
                &$scanned,
                &$convertible,
                &$alreadyMigrated,
                &$skipped,
                &$written,
            ): void {
                foreach ($records as $record) {
                    $scanned++;
                    $values = $record->values;

                    if (! is_array($values)) {
                        $skipped++;

                        continue;
                    }

                    $migrated = $this->migrate($values);

                    if ($migrated === null) {
                        $alreadyMigrated++;

                        continue;
                    }

                    $convertible++;

                    if ($dryRun) {
                        continue;
                    }

                    $record->forceFill(['values' => $migrated])->save();
                    $written++;
                }
            });

        $this->info('Account lifecycle snapshot backfill');
        $this->line("Deleted user rows scanned: {$scanned}");
        $this->line("Legacy rows convertible:   {$convertible}");
        $this->line("Already migrated:          {$alreadyMigrated}");
        $this->line("Skipped (no snapshot):     {$skipped}");

        if ($dryRun) {
            $this->warn('DRY RUN — no rows were written.');

            return self::SUCCESS;
        }

        $this->line("Rows written:              {$written}");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>|null
     */
    private function migrate(array $values): ?array
    {
        $affiliateKey = 'deleted_affiliate_tracking_snapshot';
        $hasAffiliateSnapshot = array_key_exists($affiliateKey, $values);
        $snapshot = $values['deleted_relations_snapshot'] ?? null;
        $snapshot = is_array($snapshot) ? $snapshot : [];
        $hasLegacyKeys = array_intersect(array_keys($snapshot), self::LEGACY_DETECTION_KEYS) !== [];
        $hasLegacySavedSearches = array_key_exists('saved_searches', $snapshot)
            && is_array($snapshot['saved_searches'])
            && ! array_key_exists('records', $snapshot['saved_searches']);

        if (! $hasLegacyKeys && ! $hasLegacySavedSearches && ! $hasAffiliateSnapshot) {
            return null;
        }

        $legacy = is_array($snapshot['legacy'] ?? null) ? $snapshot['legacy'] : $snapshot;

        foreach ([...self::LEGACY_TOP_LEVEL_KEYS, 'reports', 'identity', 'saved_searches', 'legacy', 'share_tracking'] as $key) {
            unset($legacy[$key]);
        }

        $affiliateSnapshot = $values[$affiliateKey] ?? [];
        $shareTracking = is_array($snapshot['share_tracking'] ?? null)
            ? $snapshot['share_tracking']
            : (is_array($affiliateSnapshot) ? $affiliateSnapshot : []);

        $identity = $snapshot['identity'] ?? null;
        $reports = $snapshot['reports'] ?? null;
        $savedSearches = $snapshot['saved_searches'] ?? null;

        $values['deleted_relations_snapshot'] = [
            'identity' => is_array($identity) && array_key_exists('social_accounts', $identity)
                ? $identity
                : ['social_accounts' => $this->rows($snapshot['social_accounts'] ?? [])],
            'reports' => is_array($reports) && (array_key_exists('report_ids', $reports) || array_key_exists('handled_report_ids', $reports))
                ? $reports
                : [
                    'report_ids' => $this->stringIds($snapshot['report_ids'] ?? []),
                    'handled_report_ids' => $this->stringIds($snapshot['handled_report_ids'] ?? []),
                ],
            'saved_searches' => is_array($savedSearches) && array_key_exists('records', $savedSearches)
                ? $savedSearches
                : ['records' => $this->rows($snapshot['saved_searches'] ?? [])],
            'legacy' => $legacy,
            'share_tracking' => $shareTracking,
        ];

        unset($values[$affiliateKey]);

        return $values;
    }

    /**
     * @return list<string>
     */
    private function stringIds(mixed $ids): array
    {
        if (! is_array($ids)) {
            return [];
        }

        return collect($ids)
            ->filter(static fn (mixed $id): bool => is_string($id) || is_int($id))
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        return collect($rows)
            ->filter(static fn (mixed $row): bool => is_array($row))
            ->values()
            ->all();
    }
}
