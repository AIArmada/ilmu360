<?php

declare(strict_types=1);

namespace Tests\Feature\ShareTracking;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use Symfony\Component\Process\Process;

/**
 * WP2 real-driver extensions over the guarded WP1 harness.
 *
 * The WP1 minimal MariaDB migration set covers only profile/link creation.
 * WP2 outcome flows additionally need the ORIGINAL affiliates
 * attribution/conversion/touchpoint/fraud/balance migrations plus the new
 * app share tables, loaded here from their current original files. The
 * WP1 harness file itself is never modified. PostgreSQL workers run the
 * full original migration set through the WP1 entrypoint, which already
 * includes every required table.
 *
 * Guarded test-only worker databases: loopback isolated ports/databases
 * only. Never the live application database (ilmu360 / 127.0.0.1:5432).
 */
final class Wp2RealDriverHarness
{
    public static function migrateWorkerDatabase(string $driver): void
    {
        Wp1RealDriverHarness::migrateWorkerDatabase($driver);

        if ($driver === 'pgsql') {
            // The isolated cluster inherits the host timezone while the
            // application writes UTC walls. Pin the worker session to UTC
            // so stored instants round-trip exactly, as in production.
            DB::connection(Wp1RealDriverHarness::WORKER_CONNECTION)
                ->statement("SET TIME ZONE 'UTC'");

            return;
        }

        foreach (self::extraMariaDbMigrationFiles() as $file) {
            Assert::assertFileExists($file);

            // require (not require_once): each call must return a fresh
            // anonymous migration instance.
            $migration = require $file;

            Assert::assertIsObject($migration, "Migration file [{$file}] must return a migration instance.");

            $migration->up();
        }
    }

    /**
     * @return list<string>
     */
    private static function extraMariaDbMigrationFiles(): array
    {
        $base = base_path();

        return [
            // Landing attributions read and written by captureRequest.
            $base.'/vendor/aiarmada/affiliates/database/migrations/2000_01_01_000002_create_affiliate_attributions_table.php',
            // Attributed outcome conversions.
            $base.'/vendor/aiarmada/affiliates/database/migrations/2000_01_01_000003_create_affiliate_conversions_table.php',
            // Visit touchpoints for the rolling dedupe window.
            $base.'/vendor/aiarmada/affiliates/database/migrations/2000_01_01_000006_create_affiliate_touchpoints_table.php',
            // Persisted fraud-rule signals.
            $base.'/vendor/aiarmada/affiliates/database/migrations/2000_01_01_000011_create_affiliate_fraud_signals_table.php',
            // Accounting balances.
            $base.'/vendor/aiarmada/affiliates/database/migrations/2000_01_01_000012_create_affiliate_balances_table.php',
            // Per-browser persistent attribution selection lock.
            $base.'/database/migrations/2026_10_06_201907_create_share_browser_selections_table.php',
            // Frozen attributed-or-unattributed source decisions.
            $base.'/database/migrations/2026_10_06_201908_create_share_source_attribution_receipts_table.php',
            // Append-only zero-value outcome corrections.
            $base.'/database/migrations/2026_10_06_201909_create_share_outcome_corrections_table.php',
            // Durable keyed effect receipts with lease fencing.
            $base.'/database/migrations/2026_10_06_201910_create_share_effect_receipts_table.php',
            // Guest credential verifiers presented on share writes.
            $base.'/database/migrations/2026_10_07_000001_create_share_guest_credentials_table.php',
        ];
    }

    public static function migrateExtendedWorkerDatabase(string $driver): void
    {
        self::migrateWorkerDatabase($driver);

        if ($driver === 'pgsql') {
            return;
        }

        foreach (self::extendedMariaDbMigrationFiles() as $file) {
            Assert::assertFileExists($file);

            $migration = require $file;

            Assert::assertIsObject($migration, "Migration file [{$file}] must return a migration instance.");

            $migration->up();
        }
    }

    /**
     * @return list<string>
     */
    private static function extendedMariaDbMigrationFiles(): array
    {
        $base = base_path();

        return [
            $base.'/vendor/aiarmada/events/database/migrations/2000_01_01_000001_create_events_table.php',
            $base.'/vendor/aiarmada/events/database/migrations/2000_01_01_000002_create_event_occurrences_table.php',
            $base.'/vendor/aiarmada/events/database/migrations/2000_01_01_000003_create_event_sessions_table.php',
            $base.'/vendor/aiarmada/events/database/migrations/2000_01_01_000004_create_event_venues_table.php',
            $base.'/vendor/aiarmada/events/database/migrations/2000_01_01_000013_create_event_access_policies_table.php',
            $base.'/vendor/aiarmada/events/database/migrations/2000_01_01_000014_create_event_registrations_table.php',
            $base.'/vendor/aiarmada/events/database/migrations/2000_01_01_000015_create_event_registration_participants_table.php',
            $base.'/vendor/aiarmada/events/database/migrations/2000_01_01_000018_create_event_attendances_table.php',
            $base.'/vendor/aiarmada/events/database/migrations/2000_01_01_000019_create_event_attendance_logs_table.php',
            $base.'/vendor/aiarmada/ticketing/database/migrations/2000_01_01_000001_create_ticket_types_table.php',
            $base.'/vendor/aiarmada/ticketing/database/migrations/2000_01_01_000002_create_ticket_type_components_table.php',
            // Required by the prepare path (auto-bundle inclusion check);
            // the owning migration carries an explicit short index name
            // for MariaDB's 64-char identifier limit (R5).
            $base.'/vendor/aiarmada/ticketing/database/migrations/2000_01_01_000003_create_ticket_type_products_table.php',
            $base.'/vendor/aiarmada/ticketing/database/migrations/2000_01_01_000007_create_ticket_type_seating_options_table.php',
            $base.'/vendor/aiarmada/inventory/database/migrations/2000_09_01_000001_create_inventory_locations_table.php',
            $base.'/vendor/aiarmada/inventory/database/migrations/2000_09_01_000002_create_inventory_levels_table.php',
            $base.'/vendor/aiarmada/inventory/database/migrations/2000_09_01_000003_create_inventory_movements_table.php',
            $base.'/vendor/aiarmada/inventory/database/migrations/2000_09_01_000004_create_inventory_allocations_table.php',
            $base.'/vendor/aiarmada/inventory/database/migrations/2000_09_01_000016_create_inventory_reservations_table.php',
            $base.'/vendor/aiarmada/cart/database/migrations/2000_02_01_000001_create_carts_table.php',
            $base.'/vendor/aiarmada/cart/database/migrations/2000_02_01_000002_create_cart_conditions_table.php',
            $base.'/vendor/aiarmada/cart/database/migrations/2000_02_01_000003_create_cart_snapshots_table.php',
            $base.'/vendor/aiarmada/cart/database/migrations/2000_02_01_000004_create_cart_snapshots_items_table.php',
            $base.'/vendor/aiarmada/cart/database/migrations/2000_02_01_000005_create_cart_snapshots_conditions_table.php',
            $base.'/vendor/aiarmada/checkout/database/migrations/2024_01_01_000001_create_checkout_sessions_table.php',
            $base.'/vendor/aiarmada/orders/database/migrations/2000_11_01_000001_create_orders_table.php',
            $base.'/vendor/aiarmada/orders/database/migrations/2000_11_01_000002_create_order_items_table.php',
        ];
    }

    /**
     * Build a GET request the real captureRequest accepts: the route is
     * genuinely matched by the application router (no database), and the
     * optional cookie carries real encoded browser state from an earlier
     * captureRequest call.
     */
    public static function landingRequest(string $url, ?string $cookie = null): Request
    {
        $request = Request::create($url, 'GET');

        $route = app('router')->getRoutes()->match($request);

        $request->setRouteResolver(static fn (): mixed => $route);

        if (is_string($cookie) && $cookie !== '') {
            $request->cookies->set((string) config('dawah-share.cookie.name', 'mi_dawah_share'), $cookie);
        }

        return $request;
    }

    /**
     * Run two independent race workers as separate OS processes with
     * independent connections, started together behind a file barrier.
     *
     * @param  array{driver: string, database: string, app_url: string, mode: string, payload: array<string, mixed>}  $job
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function runRace(array $job): array
    {
        $readyDir = sys_get_temp_dir().'/wp2-race-'.$job['database'];

        if (! is_dir($readyDir) && ! mkdir($readyDir, 0777, true) && ! is_dir($readyDir)) {
            Assert::fail('Unable to create race barrier directory.');
        }

        $worker = __DIR__.'/Wp2RaceWorker.php';

        Assert::assertFileExists($worker);

        $baseConfig = Wp1RealDriverHarness::driverConfigs()[$job['driver']];

        // Slot inputs travel through the barrier directory: cookie state
        // and keys are argv-hostile, and both workers must read identical
        // bytes.
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

    /**
     * Run two landing workers as separate OS processes with a controlled
     * commit order: both boot behind the worker start barrier, then the
     * parent releases $firstSlot's turn, waits for its landing to commit,
     * and only then releases the other slot. Real OS processes, real
     * connections, real selection-row locking — with a deterministic
     * winner instead of a timing lottery.
     *
     * @param  array{driver: string, database: string, app_url: string, mode: string, payload: array<string, mixed>}  $job
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function runOrderedLandings(array $job, int $firstSlot): array
    {
        Assert::assertContains($firstSlot, [0, 1], 'Ordered landings need slot 0 or 1 first.');
        Assert::assertSame('landing_ordered', $job['mode'], 'Ordered landings need the landing_ordered worker mode.');

        $secondSlot = $firstSlot === 0 ? 1 : 0;
        $readyDir = sys_get_temp_dir().'/wp2-race-'.$job['database'];

        if (! is_dir($readyDir) && ! mkdir($readyDir, 0777, true) && ! is_dir($readyDir)) {
            Assert::fail('Unable to create race barrier directory.');
        }

        // A crashed run must never satisfy this run's barrier or turns.
        foreach (glob($readyDir.'/ready-*') ?: [] as $stale) {
            @unlink($stale);
        }

        foreach (glob($readyDir.'/turn-*') ?: [] as $stale) {
            @unlink($stale);
        }

        foreach (glob($readyDir.'/done-*') ?: [] as $stale) {
            @unlink($stale);
        }

        $worker = __DIR__.'/Wp2RaceWorker.php';

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

        // Both workers must reach the start barrier before either lands.
        self::waitForRaceFile($readyDir.'/ready-0', 'First ordered worker never reached the start barrier.');
        self::waitForRaceFile($readyDir.'/ready-1', 'Second ordered worker never reached the start barrier.');

        file_put_contents($readyDir.'/turn-'.$firstSlot, 'go');
        self::waitForRaceFile($readyDir.'/done-'.$firstSlot, 'First ordered landing never committed.', 120);

        file_put_contents($readyDir.'/turn-'.$secondSlot, 'go');
        self::waitForRaceFile($readyDir.'/done-'.$secondSlot, 'Second ordered landing never committed.', 120);

        $results = [];

        foreach ($processes as $slot => $process) {
            $process->wait();

            $output = trim($process->getOutput());
            $decoded = json_decode($output, true);

            Assert::assertSame(
                0,
                $process->getExitCode(),
                "Ordered worker {$slot} failed (exit {$process->getExitCode()}): {$output} {$process->getErrorOutput()}"
            );
            Assert::assertIsArray($decoded, "Ordered worker {$slot} must report JSON.");
            Assert::assertTrue((bool) ($decoded['ok'] ?? false), "Ordered worker {$slot} must succeed: ".json_encode($decoded));

            $results[$slot] = $decoded;
        }

        return [$results[0], $results[1]];
    }

    private static function waitForRaceFile(string $path, string $failure, int $seconds = 60): void
    {
        $deadline = microtime(true) + $seconds;

        while (! is_file($path)) {
            if (microtime(true) > $deadline) {
                Assert::fail($failure.' ['.$path.']');
            }

            usleep(5000);
        }
    }
}
