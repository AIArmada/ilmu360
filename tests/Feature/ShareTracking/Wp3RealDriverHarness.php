<?php

declare(strict_types=1);

namespace Tests\Feature\ShareTracking;

use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Assert;
use Symfony\Component\Process\Process;

// WP3 real-driver harness: worker databases with the dedicated
// share_rewards_test_wp3_* prefix on the isolated PostgreSQL 17
// (port 45432) and MariaDB 11.4 (port 43306) fixtures, original
// migrations plus the WP2 minimal MariaDB set plus the two WP3 claim
// tables, and the WP3 OS-process race runner. Base guards, configs,
// and connections are reused from the WP1 harness; the WP2 migration
// set is reused untouched. This file is WP3-owned and may grow WP3
// extras; the WP1/WP2 harness files are never modified.
final class Wp3RealDriverHarness
{
    public static function workerDatabaseName(string $driver, string $test): string
    {
        $token = preg_replace('/[^a-z0-9]+/', '_', strtolower((string) (ParallelTesting::token() ?: 'local')));
        $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower($test));

        $suffix = 'wp3_'.$driver.'_'.trim((string) $token, '_').'_'.(string) (getmypid() ?: 'na').'_'.trim((string) $slug, '_');

        return 'share_rewards_test_'.substr($suffix, 0, 43);
    }

    public static function createWorkerDatabase(string $driver, string $test): string
    {
        $database = self::workerDatabaseName($driver, $test);

        Wp1RealDriverHarness::guardWorkerDatabaseName($database);

        $connection = Wp1RealDriverHarness::baseConnection($driver);

        Wp1RealDriverHarness::assertServerIdentity($driver, $connection);

        // Idempotent: a crashed run must never block the next one. The
        // guarded namespace makes drop-if-exists safe.
        Wp1RealDriverHarness::dropWorkerDatabase($driver, $database);

        if ($driver === 'pgsql') {
            $connection->statement('CREATE DATABASE "'.$database.'"');
        } else {
            $connection->statement('CREATE DATABASE `'.$database.'`');
        }

        return $database;
    }

    public static function migrateWorkerDatabase(string $driver): void
    {
        Wp2RealDriverHarness::migrateWorkerDatabase($driver);

        if ($driver !== 'mariadb') {
            return;
        }

        foreach (self::extraMariaDbMigrationFiles() as $path) {
            Assert::assertFileExists($path);

            // require (not require_once): each call must return a fresh
            // anonymous migration instance. Same inline pattern as the WP2
            // harness; the WP1/WP2 files stay untouched.
            $migration = require $path;

            Assert::assertIsObject($migration, "Migration file [{$path}] must return a migration instance.");

            $migration->up();
        }

        self::migrateSocialProfilesForMariaDb();
    }

    /**
     * Extra ORIGINAL migrations for worker databases that exercise the
     * real production `$user->delete()` lifecycle (snapshot, deletion
     * hook, physical removal). PostgreSQL runs the full original
     * migration set already, so this is a MariaDB-only extension. Every
     * file below is a current original that must run in full; nothing is
     * tolerated, skipped, or hand-built.
     */
    public static function migrateDeleteWorkerExtras(string $driver): void
    {
        if ($driver !== 'mariadb') {
            return;
        }

        foreach (self::deleteMariaDbMigrationFiles() as $path) {
            Assert::assertFileExists($path);

            // require (not require_once): each call must return a fresh
            // anonymous migration instance.
            $migration = require $path;

            Assert::assertIsObject($migration, "Migration file [{$path}] must return a migration instance.");

            $migration->up();
        }
    }

    /**
     * @return list<string>
     */
    private static function deleteMariaDbMigrationFiles(): array
    {
        $base = base_path();

        return [
            // Deletion audit trail + removed-user records.
            $base.'/database/migrations/2026_01_11_090456_create_audits_table.php',
            $base.'/database/migrations/2026_01_23_074047_create_deleted_models_table.php',
            // Authentication state cleared by the deletion hook.
            $base.'/database/migrations/2026_01_25_175141_create_socialite_table.php',
            $base.'/database/migrations/2026_07_07_075147_create_personal_access_tokens_table.php',
            $base.'/database/migrations/2026_04_17_131610_create_oauth_auth_codes_table.php',
            $base.'/database/migrations/2026_04_17_131611_create_oauth_access_tokens_table.php',
            $base.'/database/migrations/2026_04_17_131612_create_oauth_refresh_tokens_table.php',
            $base.'/database/migrations/2026_04_17_131614_create_oauth_device_codes_table.php',
            // Membership pivots detached by the hook.
            $base.'/database/migrations/2026_07_06_192514_create_uniform_membership_pivots.php',
            // Directory/verification relations snapshotted and detached.
            $base.'/database/migrations/2026_01_10_000007_create_institutions_table.php',
            $base.'/database/migrations/2026_06_30_002300_create_contribution_requests_table.php',
            $base.'/database/migrations/2026_01_10_000021_create_reports_table.php',
            $base.'/database/migrations/2026_01_10_000022_create_saved_searches_table.php',
            $base.'/database/migrations/2026_02_12_060000_create_ai_usage_logs_table.php',
            $base.'/database/migrations/2026_01_10_000012_create_donation_channels_table.php',
            $base.'/vendor/aiarmada/persons/database/migrations/2000_01_01_000001_create_persons_table.php',
            $base.'/vendor/aiarmada/references/database/migrations/2000_01_01_000001_create_references_table.php',
            // Engagement relations cleared by the hook.
            $base.'/vendor/aiarmada/engagement/database/migrations/2000_06_01_000001_create_engagement_follows_table.php',
            $base.'/vendor/aiarmada/engagement/database/migrations/2000_06_01_000002_create_engagement_bookmarks_table.php',
            $base.'/vendor/aiarmada/engagement/database/migrations/2000_06_01_000005_create_engagement_responses_table.php',
            // Event relations cleared by the hook.
            $base.'/vendor/aiarmada/events/database/migrations/2000_01_01_000001_create_events_table.php',
            $base.'/vendor/aiarmada/events/database/migrations/2000_01_01_000004_create_event_venues_table.php',
            $base.'/vendor/aiarmada/events/database/migrations/2000_01_01_000014_create_event_registrations_table.php',
            $base.'/vendor/aiarmada/events/database/migrations/2000_01_01_000018_create_event_attendances_table.php',
            $base.'/vendor/aiarmada/events/database/migrations/2000_01_01_000040_create_event_submissions_table.php',
            $base.'/vendor/aiarmada/membership/database/migrations/2000_01_01_000001_create_membership_applications_table.php',
            $base.'/vendor/aiarmada/moderation/database/migrations/2000_01_01_000002_create_moderation_actions_table.php',
            // Notification relations cleared by the hook.
            $base.'/vendor/aiarmada/communications/database/migrations/2000_01_01_000011_create_communication_preferences_table.php',
            $base.'/vendor/aiarmada/communications/database/migrations/2000_01_01_000016_create_communication_notification_inboxes_table.php',
            $base.'/vendor/aiarmada/communications/database/migrations/2000_01_01_000017_create_communication_destinations_table.php',
            $base.'/vendor/aiarmada/authz/database/migrations/2000_01_01_000002_create_authz_permission_tables.php',
            // Affiliation scope plus the verified/rejected follow-up
            // columns the hook nulls out (original order preserved:
            // base tables first, follow-up last).
            $base.'/vendor/aiarmada/persons/database/migrations/2000_01_01_000009_create_affiliations_table.php',
            $base.'/database/migrations/2026_07_25_140600_add_verified_by_to_references_and_position_to_affiliations.php',
        ];
    }

    private static function migrateSocialProfilesForMariaDb(): void
    {
        $path = base_path('vendor/aiarmada/contacting/database/migrations/2000_01_01_000003_create_contact_social_profiles_table.php');

        Assert::assertFileExists($path);

        $migration = require $path;

        Assert::assertIsObject($migration, "Migration file [{$path}] must return a migration instance.");

        // The canonical owning migration runs unmodified and must succeed
        // in full: every validity/primary index is required worker schema.
        // No failure is tolerated or swallowed here.
        $migration->up();

        Assert::assertTrue(Schema::hasTable('social_profiles'), 'The social_profiles worker table must exist.');
    }

    /**
     * @return list<string>
     */
    private static function extraMariaDbMigrationFiles(): array
    {
        $base = base_path();

        return [
            $base.'/database/migrations/2026_10_07_000002_create_share_guest_claim_capabilities_table.php',
            $base.'/database/migrations/2026_10_07_000003_create_share_guest_claims_table.php',
            // Claim-vs-delete coordination additionally needs the original
            // payout, payout-method, payout-hold, and daily-stats tables:
            // the member-affiliate delete cascade and the financial-record
            // guard read them on every tracking cleanup. Social profiles
            // migrate separately below (canonical original file, unsweetened).
            $base.'/vendor/aiarmada/affiliates/database/migrations/2000_01_01_000004_create_affiliate_payouts_table.php',
            $base.'/vendor/aiarmada/affiliates/database/migrations/2000_01_01_000010_create_affiliate_daily_stats_table.php',
            $base.'/vendor/aiarmada/affiliates/database/migrations/2000_01_01_000013_create_affiliate_payout_methods_table.php',
            $base.'/vendor/aiarmada/affiliates/database/migrations/2000_01_01_000014_create_affiliate_payout_holds_table.php',
        ];
    }

    /**
     * Explicit schema proof: every table the WP3 races touch must exist
     * after migration. A swallowed migration failure can never masquerade
     * as a passing race.
     *
     * @param  list<string>  $tables
     */
    public static function assertWorkerSchema(array $tables): void
    {
        foreach ($tables as $table) {
            Assert::assertTrue(
                Schema::connection(Wp1RealDriverHarness::WORKER_CONNECTION)->hasTable($table),
                "Worker database is missing migrated table [{$table}]."
            );
        }
    }

    /**
     * Run one solo worker against a parent-held lock, forcing a genuine
     * empty-lookup overlap: the worker must attempt its creation BEFORE
     * the parent's $commitStep commits, so its lookup blocks on the
     * parent-held row lock and then reuses the committed row. Returns the
     * commit report and the worker report. Worker errors and exits are
     * asserted exactly like the two-worker race.
     *
     * @param  array{database: string, driver: string, app_url: string, mode: string, payload: array<string, mixed>}  $job
     * @param  callable(): array<string, mixed>  $commitStep
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function runForcedSolo(array $job, callable $commitStep): array
    {
        $readyDir = sys_get_temp_dir().'/wp3-race-'.$job['database'];

        if (! is_dir($readyDir) && ! mkdir($readyDir, 0777, true) && ! is_dir($readyDir)) {
            Assert::fail('Unable to create race barrier directory.');
        }

        foreach (glob($readyDir.'/attempting-*') ?: [] as $stale) {
            @unlink($stale);
        }

        $worker = __DIR__.'/Wp3RaceWorker.php';

        Assert::assertFileExists($worker);

        $baseConfig = Wp1RealDriverHarness::driverConfigs()[$job['driver']];

        file_put_contents(
            $readyDir.'/payload.json',
            json_encode($job['payload'] + ['solo' => true], JSON_THROW_ON_ERROR)
        );

        $process = new Process(
            command: [
                PHP_BINARY,
                $worker,
                '--driver='.$job['driver'],
                '--host='.$baseConfig['host'],
                '--port='.$baseConfig['port'],
                '--database='.$job['database'],
                '--username='.$baseConfig['username'],
                '--password='.$baseConfig['password'],
                '--app-url='.$job['app_url'],
                '--mode='.$job['mode'],
                '--ready-dir='.$readyDir,
                '--slot=0',
            ],
            env: ['APP_ENV' => 'testing'],
            timeout: 150,
        );

        $process->start();

        $deadline = microtime(true) + 60;

        while (! is_file($readyDir.'/attempting-0')) {
            if (microtime(true) > $deadline) {
                $process->stop();

                Assert::fail('Solo race worker never attempted its creation.');
            }

            usleep(5000);
        }

        // Let the worker arrive at the lock wait before committing, so
        // the blocked duration measures a real lock hold, not a boot
        // race. The overlap itself is structural (attempt precedes
        // commit by protocol), never timing luck.
        usleep(1500000);

        $commit = $commitStep();

        $process->wait();

        $output = trim($process->getOutput());
        $decoded = json_decode($output, true);

        Assert::assertSame(
            0,
            $process->getExitCode(),
            "Solo race worker failed (exit {$process->getExitCode()}): {$output} {$process->getErrorOutput()}"
        );
        Assert::assertIsArray($decoded, 'Solo race worker must report JSON.');
        Assert::assertTrue((bool) ($decoded['ok'] ?? false), 'Solo race worker must succeed: '.json_encode($decoded));

        return [$commit, $decoded];
    }

    /**
     * @param  array{database: string, driver: string, app_url: string, mode: string, payload: array<string, mixed>}  $job
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function runRace(array $job): array
    {
        $readyDir = sys_get_temp_dir().'/wp3-race-'.$job['database'];

        if (! is_dir($readyDir) && ! mkdir($readyDir, 0777, true) && ! is_dir($readyDir)) {
            Assert::fail('Unable to create race barrier directory.');
        }

        $worker = __DIR__.'/Wp3RaceWorker.php';

        Assert::assertFileExists($worker);

        $baseConfig = Wp1RealDriverHarness::driverConfigs()[$job['driver']];

        file_put_contents(
            $readyDir.'/payload.json',
            json_encode($job['payload'], JSON_THROW_ON_ERROR)
        );

        $processes = [];

        foreach ([0, 1] as $slot) {
            $processes[$slot] = new Process(
                command: [
                    PHP_BINARY,
                    $worker,
                    '--driver='.$job['driver'],
                    '--host='.$baseConfig['host'],
                    '--port='.$baseConfig['port'],
                    '--database='.$job['database'],
                    '--username='.$baseConfig['username'],
                    '--password='.$baseConfig['password'],
                    '--app-url='.$job['app_url'],
                    '--mode='.$job['mode'],
                    '--ready-dir='.$readyDir,
                    '--slot='.$slot,
                ],
                env: ['APP_ENV' => 'testing'],
                timeout: 150,
            );

            $processes[$slot]->start();
        }

        $results = [];

        foreach ($processes as $slot => $process) {
            $process->wait();

            $output = trim($process->getOutput());
            $decoded = json_decode($output, true);

            Assert::assertSame(
                0,
                $process->getExitCode(),
                "Race worker {$slot} failed (exit {$process->getExitCode()}): {$output} {$process->getErrorOutput()}"
            );
            Assert::assertIsArray($decoded, "Race worker {$slot} must report JSON.");
            Assert::assertTrue((bool) ($decoded['ok'] ?? false), "Race worker {$slot} must succeed: ".json_encode($decoded));

            $results[$slot] = $decoded;
        }

        return [$results[0], $results[1]];
    }
}
