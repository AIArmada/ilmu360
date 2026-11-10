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
     * Feature-owned keys that are moved out of the conventional legacy map.
     *
     * @var array<int, string>
     */
    private const array FEATURE_SNAPSHOT_KEYS = [
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
        'share_tracking',
        'legacy',
    ];

    /**
     * Flat legacy keys consumed by one of the feature snapshots.
     *
     * @var array<int, string>
     */
    private const array LEGACY_SOURCE_KEYS = [
        'social_accounts',
        'report_ids',
        'handled_report_ids',
        'saved_searches',
        'institution_members',
        'person_members',
        'reference_members',
        'event_members',
        'membership_claim_ids',
        'membership_application_reviewer_ids',
        'scoped_roles',
        'scoped_permissions',
        'verified_donation_channel_ids',
        'verified_person_ids',
        'verified_institution_ids',
        'verified_reference_ids',
        'verified_venue_ids',
        'owned_event_ids',
        'submitted_event_ids',
        'event_submission_ids',
        'verified_event_checkin_ids',
        'registrations',
        'event_checkins',
        'contribution_request_proposer_ids',
        'contribution_request_reviewer_ids',
        'moderation_review_ids',
        'user_venue',
        'event_saves',
        'event_attendees',
        'followings',
        'ai_usage_logs',
        'notification_setting',
        'notification_destinations',
        'notification_inboxes',
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
        $snapshot = $values['deleted_relations_snapshot'] ?? null;
        $snapshot = is_array($snapshot) ? $snapshot : [];

        $source = is_array($snapshot['legacy'] ?? null) ? $snapshot['legacy'] : $snapshot;

        $legacy = $source;

        foreach ([...self::LEGACY_SOURCE_KEYS, ...self::FEATURE_SNAPSHOT_KEYS] as $key) {
            unset($legacy[$key]);
        }

        $affiliateSnapshot = $values[$affiliateKey] ?? [];
        $shareTracking = is_array($snapshot['share_tracking'] ?? null)
            ? $snapshot['share_tracking']
            : (is_array($affiliateSnapshot) ? $affiliateSnapshot : []);

        $migrated = $values;
        $migrated['deleted_relations_snapshot'] = [
            'identity' => $this->identitySnapshot($snapshot, $source),
            'reports' => $this->reportsSnapshot($snapshot, $source),
            'saved_searches' => $this->savedSearchesSnapshot($snapshot, $source),
            'membership' => $this->membershipSnapshot($snapshot, $source),
            'directory' => $this->directorySnapshot($snapshot, $source),
            'events' => $this->eventsSnapshot($snapshot, $source),
            'moderation' => $this->moderationSnapshot($snapshot, $source),
            'contributions' => $this->contributionsSnapshot($snapshot, $source),
            'engagement' => $this->engagementSnapshot($snapshot, $source),
            'ai' => $this->aiSnapshot($snapshot, $source),
            'communications' => $this->communicationsSnapshot($snapshot, $source),
            'legacy' => $legacy,
            'share_tracking' => $shareTracking,
        ];

        unset($migrated[$affiliateKey]);

        if ($migrated == $values) {
            return null;
        }

        return $migrated;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function identitySnapshot(array $snapshot, array $source): array
    {
        $identity = is_array($snapshot['identity'] ?? null) ? $snapshot['identity'] : [];

        $identity['social_accounts'] ??= $this->rows($source['social_accounts'] ?? []);

        return $identity;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function reportsSnapshot(array $snapshot, array $source): array
    {
        $reports = is_array($snapshot['reports'] ?? null) ? $snapshot['reports'] : [];

        $reports['report_ids'] ??= $this->stringIds($source['report_ids'] ?? []);
        $reports['handled_report_ids'] ??= $this->stringIds($source['handled_report_ids'] ?? []);

        return $reports;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function savedSearchesSnapshot(array $snapshot, array $source): array
    {
        $savedSearches = is_array($snapshot['saved_searches'] ?? null) ? $snapshot['saved_searches'] : [];
        $legacyRecords = is_array($source['saved_searches'] ?? null) ? $source['saved_searches'] : [];

        // A flat list is the legacy shape; the contributor shape wraps it in
        // a `records` key.
        if (! array_key_exists('records', $savedSearches) && array_is_list($legacyRecords)) {
            $savedSearches = ['records' => $this->rows($legacyRecords)];
        }

        $savedSearches['records'] ??= [];

        return $savedSearches;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function membershipSnapshot(array $snapshot, array $source): array
    {
        $membership = is_array($snapshot['membership'] ?? null) ? $snapshot['membership'] : [];

        foreach (['institution_members', 'person_members', 'reference_members', 'event_members'] as $key) {
            $membership[$key] ??= $this->rows($source[$key] ?? []);
        }

        $membership['membership_claim_ids'] ??= $this->stringIds($source['membership_claim_ids'] ?? []);
        $membership['membership_application_reviewer_ids'] ??= $this->stringIds($source['membership_application_reviewer_ids'] ?? []);
        $membership['scoped_roles'] ??= $this->rows($source['scoped_roles'] ?? []);
        $membership['scoped_permissions'] ??= $this->rows($source['scoped_permissions'] ?? []);

        return $membership;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function directorySnapshot(array $snapshot, array $source): array
    {
        $directory = is_array($snapshot['directory'] ?? null) ? $snapshot['directory'] : [];

        foreach ([
            'verified_donation_channel_ids',
            'verified_person_ids',
            'verified_institution_ids',
            'verified_reference_ids',
            'verified_venue_ids',
        ] as $key) {
            $directory[$key] ??= $this->stringIds($source[$key] ?? []);
        }

        return $directory;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function eventsSnapshot(array $snapshot, array $source): array
    {
        $events = is_array($snapshot['events'] ?? null) ? $snapshot['events'] : [];

        foreach (['owned_event_ids', 'submitted_event_ids', 'event_submission_ids', 'verified_event_checkin_ids'] as $key) {
            $events[$key] ??= $this->stringIds($source[$key] ?? []);
        }

        $registrations = $this->rows($source['registrations'] ?? []);
        $eventCheckins = $this->rows($source['event_checkins'] ?? []);

        $events['registrations'] ??= $registrations;
        $events['event_checkins'] ??= $eventCheckins;
        $events['registration_ids'] ??= $this->rowIds($registrations);
        $events['event_checkin_ids'] ??= $this->rowIds($eventCheckins);

        return $events;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function moderationSnapshot(array $snapshot, array $source): array
    {
        $moderation = is_array($snapshot['moderation'] ?? null) ? $snapshot['moderation'] : [];

        $moderation['moderation_review_ids'] ??= $this->stringIds($source['moderation_review_ids'] ?? []);

        return $moderation;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function contributionsSnapshot(array $snapshot, array $source): array
    {
        $contributions = is_array($snapshot['contributions'] ?? null) ? $snapshot['contributions'] : [];

        $contributions['proposer_ids'] ??= $this->stringIds($source['contribution_request_proposer_ids'] ?? []);
        $contributions['reviewer_ids'] ??= $this->stringIds($source['contribution_request_reviewer_ids'] ?? []);

        return $contributions;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function engagementSnapshot(array $snapshot, array $source): array
    {
        $engagement = is_array($snapshot['engagement'] ?? null) ? $snapshot['engagement'] : [];

        foreach (['user_venue', 'event_saves', 'event_attendees', 'followings'] as $key) {
            $engagement[$key] ??= $this->rows($source[$key] ?? []);
        }

        return $engagement;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function aiSnapshot(array $snapshot, array $source): array
    {
        $ai = is_array($snapshot['ai'] ?? null) ? $snapshot['ai'] : [];
        $ai['ai_usage_logs'] ??= $this->rows($source['ai_usage_logs'] ?? []);

        return $ai;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function communicationsSnapshot(array $snapshot, array $source): array
    {
        $communications = is_array($snapshot['communications'] ?? null) ? $snapshot['communications'] : [];
        $setting = $source['notification_setting'] ?? null;

        $communications['notification_setting'] ??= is_array($setting) ? $setting : null;
        $communications['notification_destinations'] ??= $this->rows($source['notification_destinations'] ?? []);
        $communications['notification_inboxes'] ??= $this->rows($source['notification_inboxes'] ?? []);

        return $communications;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function rowIds(array $rows): array
    {
        return collect($rows)
            ->pluck('id')
            ->filter(static fn (mixed $id): bool => is_string($id) || is_int($id))
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
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
