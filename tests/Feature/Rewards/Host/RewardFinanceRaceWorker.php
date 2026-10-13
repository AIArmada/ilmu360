<?php

declare(strict_types=1);

// Permanent race worker for RewardFinanceRaceTest. Executed only as a standalone
// OS process (`php RewardFinanceRaceWorker.php --driver=... ...`); returns
// immediately when merely included, so test runners never execute it.

use AIArmada\Rewards\Actions\CancelRewardBenefitGrantAction;
use AIArmada\Rewards\Actions\ClawBackAwardPointsAction;
use AIArmada\Rewards\Actions\FulfillRewardBenefitAction;
use AIArmada\Rewards\Actions\MarkRewardReservationSubmittedAction;
use AIArmada\Rewards\Actions\RedeemRewardBenefitAction;
use AIArmada\Rewards\Actions\RevokeRewardBenefitGrantAction;
use AIArmada\Rewards\Models\RewardPointReservationSubmission;
use AIArmada\Vouchers\Actions\RecordVoucherUsage;
use Akaunting\Money\Currency;
use Akaunting\Money\Money;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

if (PHP_SAPI !== 'cli' || realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}

$options = getopt('', [
    'driver:',
    'host:',
    'port:',
    'database:',
    'username:',
    'password:',
    'mode:',
    'ready-dir:',
    'slot:',
]);

$fail = static function (string $message): never {
    echo json_encode(['ok' => false, 'error' => $message], JSON_THROW_ON_ERROR)."\n";

    exit(1);
};

$driver = (string) ($options['driver'] ?? '');
$host = (string) ($options['host'] ?? '');
$port = (int) ($options['port'] ?? 0);
$database = (string) ($options['database'] ?? '');
$mode = (string) ($options['mode'] ?? '');
$readyDir = (string) ($options['ready-dir'] ?? '');
$slot = (int) ($options['slot'] ?? -1);

if ($host !== '127.0.0.1') {
    $fail('Refusing a non-loopback host.');
}

if (($driver === 'pgsql' && $port !== 45432) || ($driver === 'mariadb' && $port !== 43306)) {
    $fail('Refusing a non-isolated database port.');
}

if (preg_match('/^share_rewards_test_rewards_finance_[a-z0-9_]{1,27}$/', $database) !== 1) {
    $fail('Refusing a database outside the isolated rewards-finance namespace.');
}

if (! in_array($mode, ['clawback', 'mark', 'redeem', 'fulfill', 'cancel', 'revoke', 'record_usage'], true) || $readyDir === '' || ! in_array($slot, [0, 1], true)) {
    $fail('Missing race job arguments.');
}

$payloadFile = $readyDir.'/payload.json';

if (! is_file($payloadFile)) {
    $fail('Missing race payload.');
}

$payload = json_decode((string) file_get_contents($payloadFile), true);

if (! is_array($payload)) {
    $fail('Race payload must be JSON.');
}

$basePath = realpath(__DIR__.'/../../../../');

if (! is_string($basePath) || ! is_file($basePath.'/bootstrap/app.php')) {
    $fail('Unable to locate the application base path.');
}

require $basePath.'/vendor/autoload.php';

$app = require $basePath.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$workerConfig = $driver === 'pgsql'
    ? [
        'driver' => 'pgsql',
        'host' => $host,
        'port' => $port,
        'database' => $database,
        'username' => (string) ($options['username'] ?? ''),
        'password' => (string) ($options['password'] ?? ''),
        'charset' => 'utf8',
        'prefix' => '',
        'prefix_indexes' => true,
        'search_path' => 'public',
        'sslmode' => 'prefer',
    ]
    : [
        'driver' => 'mariadb',
        'host' => $host,
        'port' => $port,
        'database' => $database,
        'username' => (string) ($options['username'] ?? ''),
        'password' => (string) ($options['password'] ?? ''),
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'prefix_indexes' => true,
        'strict' => true,
        'engine' => null,
        'timezone' => '+00:00',
    ];

config(['database.connections.rewards_finance_race' => $workerConfig]);
DB::purge('rewards_finance_race');
DB::setDefaultConnection('rewards_finance_race');

// File barrier: both slots start the critical section together.
file_put_contents($readyDir.'/ready-'.$slot, (string) $slot);

$deadline = microtime(true) + 25.0;

while ((! is_file($readyDir.'/ready-0') || ! is_file($readyDir.'/ready-1')) && microtime(true) < $deadline) {
    usleep(20000);
}

if (! is_file($readyDir.'/ready-0') || ! is_file($readyDir.'/ready-1')) {
    $fail('Race barrier timed out.');
}

usleep(50000 * $slot);

try {
    // Per-slot payloads let same-mode pairs race distinct terms (two
    // members, one capacity); otherwise one payload per mode (or one
    // shared payload) applies to both slots.
    $slotPayload = isset($payload['slots']) && is_array($payload['slots']) ? ($payload['slots'][$slot] ?? null) : null;
    $p = is_array($slotPayload) ? $slotPayload : (isset($payload[$mode]) && is_array($payload[$mode]) ? $payload[$mode] : $payload);

    $result = match ($mode) {
        // Real reversal driver: container resolution injects the production
        // lot-locked reservation attestation — no fixture resolver.
        'clawback' => (function () use ($p): array {
            $settlement = ClawBackAwardPointsAction::run(
                (string) $p['award_id'], 'user', (string) $p['staff_id'], 'staff',
                'race-reversal', CarbonImmutable::parse((string) $p['decided_at']),
            );

            return ['ok' => true, 'clawed_points' => $settlement['clawed_points'], 'consumed_points' => $settlement['consumed_points'], 'dispute_id' => $settlement['dispute_id']];
        })(),
        // Real guarded handoff driver: production lot locks + initial-mark
        // pending attestation inside the action itself.
        'mark' => (function () use ($p): array {
            $row = MarkRewardReservationSubmittedAction::run(
                (string) $p['reservation_key'],
                RewardPointReservationSubmission::STATUS_SENDING,
                (string) $p['reference'],
                CarbonImmutable::parse((string) $p['decided_at']),
            );

            return ['ok' => true, 'status' => (string) $row->getAttribute('status')];
        })(),
        // Real redemption driver: atomic lot + stock reservation with the
        // handoff mark in the same transaction.
        'redeem' => (function () use ($p): array {
            $grant = RedeemRewardBenefitAction::run(
                (string) $p['recipient_type'], (string) $p['recipient_id'], (string) $p['family_key'],
                (string) $p['item_id'], (int) $p['quantity'], (string) $p['redemption_key'], 'manual',
                'user', (string) $p['recipient_id'], 'member', null,
                CarbonImmutable::parse((string) $p['decided_at']),
            );

            return ['ok' => true, 'grant_id' => (string) $grant->getKey()];
        })(),
        // Real fulfillment driver: capture + stock consume + issue.
        'fulfill' => (function () use ($p): array {
            $grant = FulfillRewardBenefitAction::run(
                (string) $p['grant_id'],
                isset($p['handoff_reference']) ? ['handoff_reference' => (string) $p['handoff_reference']] : [],
                'user', (string) $p['staff_id'], 'staff',
                CarbonImmutable::parse((string) $p['decided_at']),
            );

            return ['ok' => true, 'status' => $grant->status->value];
        })(),
        // Real cancellation driver: restore held points + stock once.
        'cancel' => (function () use ($p): array {
            $grant = CancelRewardBenefitGrantAction::run(
                (string) $p['grant_id'], 'user', (string) $p['staff_id'], 'staff',
                (string) ($p['reason'] ?? 'race-cancel'),
                CarbonImmutable::parse((string) $p['decided_at']),
            );

            return ['ok' => true, 'status' => $grant->status->value];
        })(),
        // Real revocation driver: unused restore with linked refund.
        'revoke' => (function () use ($p): array {
            $grant = RevokeRewardBenefitGrantAction::run(
                (string) $p['grant_id'], 'user', (string) $p['staff_id'], 'staff',
                (string) ($p['reason'] ?? 'race-revoke'),
                CarbonImmutable::parse((string) $p['decided_at']),
            );

            return ['ok' => true, 'status' => $grant->status->value];
        })(),
        // Real voucher-use driver: canonical usage recording.
        'record_usage' => (function () use ($p): array {
            $redeemer = User::query()->whereKey((string) $p['redeemed_by_id'])->firstOrFail();

            $usage = RecordVoucherUsage::run(
                (string) $p['code'],
                new Money((int) $p['amount'], new Currency((string) $p['currency'])),
                'reward-benefit',
                ['idempotency_key' => (string) $p['idempotency_key']],
                $redeemer,
            );

            return ['ok' => true, 'usage_id' => (string) $usage->getKey()];
        })(),
    };

    echo json_encode($result, JSON_THROW_ON_ERROR)."\n";

    exit(0);
} catch (Throwable $throwable) {
    // Business refusals are race outcomes, not infrastructure failures:
    // report them with a zero exit so the test can assert exactly-one-wins.
    echo json_encode([
        'ok' => false,
        'error' => substr($throwable->getMessage(), 0, 300),
    ], JSON_THROW_ON_ERROR)."\n";

    exit(0);
}
