<?php

declare(strict_types=1);

// Permanent race worker for RewardLedgerRaceTest. Executed only as a standalone
// OS process (`php RewardLedgerRaceWorker.php --driver=... ...`); returns
// immediately when merely included, so test runners never execute it.

use App\Actions\Rewards\AdvanceRewardAppealAction;
use App\Actions\Rewards\CreditRewardPointsAction;
use App\Actions\Rewards\DebitRewardPointsAction;
use App\Actions\Rewards\EvaluateRewardEntitlementAction;
use App\Actions\Rewards\ExpireRewardPointsAction;
use App\Actions\Rewards\InvalidateRewardMilestoneContributionAction;
use App\Actions\Rewards\RecordRewardMilestoneContributionAction;
use App\Actions\Rewards\RefundRewardPointsAction;
use App\Actions\Rewards\ReleaseRewardPointsAction;
use App\Actions\Rewards\ReserveRewardPointsAction;
use App\Actions\Rewards\TransitionRewardAwardAction;
use App\Enums\Rewards\RewardAppealStatus;
use App\Enums\Rewards\RewardAwardStatus;
use App\Enums\Rewards\RewardBeneficiaryRole;
use App\Enums\Rewards\RewardComponent;
use App\Enums\Rewards\RewardEligibleOutcome;
use App\Enums\Rewards\RewardSourceCorrectionStatus;
use App\Enums\Rewards\RewardSourceFraudStatus;
use App\Enums\Rewards\RewardVerificationLevel;
use App\Support\Rewards\RewardSourceFact;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Rewards\Ledgers\Support\FakeRewardReservationResolver;
use Tests\Feature\Rewards\Ledgers\Support\FakeRewardSourceFactReader;

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

if (preg_match('/^share_rewards_test_rewards_ledger_[a-z0-9_]{1,30}$/', $database) !== 1) {
    $fail('Refusing a database outside the isolated rewards-ledger namespace.');
}

if (! in_array($mode, [
    'evaluate', 'cap', 'reserve', 'debit', 'expire',
    'milestone', 'correction-debit', 'correction-reverse', 'credit',
    'refund', 'appeal-advance',
    'reserve-mismatch', 'debit-mismatch', 'refund-mismatch',
    'approve', 'overturn', 'fulfill', 'invalidate',
    'reverse', 'release', 'capture',
], true) || $readyDir === '' || ! in_array($slot, [0, 1], true)) {
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

config(['database.connections.rewards_ledger_race' => $workerConfig]);
DB::purge('rewards_ledger_race');
DB::setDefaultConnection('rewards_ledger_race');

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

$buildFact = static function (array $raw): RewardSourceFact {
    return new RewardSourceFact(
        sourceBusinessKey: (string) $raw['source_business_key'],
        outcome: RewardEligibleOutcome::from((string) $raw['outcome']),
        category: $raw['category'] ?? null,
        occurredAt: CarbonImmutable::parse((string) $raw['occurred_at']),
        verification: RewardVerificationLevel::from((string) $raw['verification']),
        isFirstTime: (bool) ($raw['is_first_time'] ?? true),
        actorId: $raw['actor_id'] ?? null,
        sharerId: $raw['sharer_id'] ?? null,
        actorRole: RewardBeneficiaryRole::from((string) ($raw['actor_role'] ?? 'participant')),
        isClaimedIdentity: (bool) ($raw['is_claimed_identity'] ?? true),
        isAttributed: (bool) ($raw['is_attributed'] ?? true),
        isAmbiguousDevice: (bool) ($raw['is_ambiguous_device'] ?? false),
        isBot: (bool) ($raw['is_bot'] ?? false),
        isCancelled: (bool) ($raw['is_cancelled'] ?? false),
        isValid: (bool) ($raw['is_valid'] ?? true),
        fraudStatus: RewardSourceFraudStatus::from((string) ($raw['fraud_status'] ?? 'clear')),
        correctionStatus: RewardSourceCorrectionStatus::from((string) ($raw['correction_status'] ?? 'current')),
        correctionId: $raw['correction_id'] ?? null,
    );
};

try {
    // Mixed-mode races nest each mode's keys under its mode name so shared
    // key names (points, occurred_at) never clash; single-mode races keep
    // the historical flat payload.
    $scoped = static function (string $mode, array $payload): array {
        return isset($payload[$mode]) && is_array($payload[$mode]) ? $payload[$mode] : $payload;
    };

    $result = match ($mode) {
        'evaluate' => (function () use ($payload, $slot, $buildFact): array {
            $programs = array_values((array) $payload['program_ids']);
            $programId = (string) ($programs[$slot] ?? $programs[0]);

            $reader = new FakeRewardSourceFactReader;
            $reader->seed($buildFact((array) $payload['fact']));

            $result = (new EvaluateRewardEntitlementAction($reader))->handle(
                $programId,
                RewardComponent::from((string) $payload['component']),
                'user',
                (string) $payload['recipient_id'],
                RewardBeneficiaryRole::from((string) $payload['role']),
                (string) $payload['source'],
                CarbonImmutable::parse((string) $payload['decision']),
            );

            return [
                'ok' => true,
                'outcome' => $result->outcome->value,
                'duplicate' => $result->duplicate,
                'reasons' => $result->reasons,
                'award_id' => (string) $result->award->getKey(),
            ];
        })(),
        'cap' => (function () use ($payload, $slot, $buildFact): array {
            $sources = (array) $payload['sources'];
            $source = (string) $sources[$slot];

            $reader = new FakeRewardSourceFactReader;
            $fact = (array) $payload['fact'];
            $fact['source_business_key'] = $source;
            $reader->seed($buildFact($fact));

            $result = (new EvaluateRewardEntitlementAction($reader))->handle(
                (string) $payload['program_id'],
                RewardComponent::from((string) $payload['component']),
                'user',
                (string) $payload['recipient_id'],
                RewardBeneficiaryRole::from((string) $payload['role']),
                $source,
                CarbonImmutable::parse((string) $payload['decision']),
            );

            return [
                'ok' => true,
                'outcome' => $result->outcome->value,
                'award_id' => (string) $result->award->getKey(),
            ];
        })(),
        'reserve' => (function () use ($payload, $slot, $scoped): array {
            $p = $scoped('reserve', $payload);
            $key = ($p['same_key'] ?? false) === true
                ? (string) $p['reservation_prefix']
                : (string) $p['reservation_prefix'].'-slot-'.$slot;

            $movements = ReserveRewardPointsAction::run(
                'user',
                (string) $p['recipient_id'],
                (string) $p['family'],
                (string) $p['unit'],
                (int) $p['points'],
                $key,
                'race-reserve',
                CarbonImmutable::parse((string) $p['occurred_at']),
            );

            return ['ok' => true, 'movements' => count($movements), 'aggregate' => $movements[0]->aggregate_operation_key ?? null];
        })(),
        'debit' => (function () use ($payload, $slot, $scoped): array {
            $p = $scoped('debit', $payload);
            $key = ($p['same_key'] ?? false) === true
                ? (string) $p['operation_prefix']
                : (string) $p['operation_prefix'].'-slot-'.$slot;

            $movements = DebitRewardPointsAction::run(
                'user',
                (string) $p['recipient_id'],
                (string) $p['family'],
                (string) $p['unit'],
                (int) $p['points'],
                $key,
                'race-debit',
                null,
                CarbonImmutable::parse((string) $p['occurred_at']),
            );

            return ['ok' => true, 'movements' => count($movements), 'aggregate' => $movements[0]->aggregate_operation_key ?? null];
        })(),
        'expire' => (function () use ($payload, $scoped): array {
            $p = $scoped('expire', $payload);
            $movements = ExpireRewardPointsAction::run(
                'user',
                (string) $p['recipient_id'],
                (string) $p['family'],
                (string) $p['unit'],
                CarbonImmutable::parse((string) $p['as_of']),
            );

            return ['ok' => true, 'movements' => count($movements)];
        })(),
        'milestone' => (function () use ($payload, $slot, $buildFact): array {
            $sources = (array) $payload['sources'];
            $facts = (array) ($payload['facts'] ?? []);

            $reader = new FakeRewardSourceFactReader;

            foreach ($facts as $raw) {
                $reader->seed($buildFact((array) $raw));
            }

            $out = (new RecordRewardMilestoneContributionAction($reader))->handle(
                'user',
                (string) $payload['recipient_id'],
                (string) $payload['family'],
                (string) $payload['milestone_key'],
                (int) $payload['target_count'],
                (int) $payload['window_days'],
                RewardComponent::from((string) $payload['component']),
                (array) $payload['reward'],
                (string) $sources[$slot],
                'user',
                (string) $payload['recipient_id'],
                RewardBeneficiaryRole::Participant,
                RewardEligibleOutcome::from((string) $payload['outcome']),
                CarbonImmutable::parse((string) $payload['occurred_at']),
                (string) $payload['program_id'],
            );

            return [
                'ok' => true,
                'achieved' => $out['achieved'],
                'cycle_id' => (string) $out['cycle']->getKey(),
            ];
        })(),
        'correction-debit' => (function () use ($payload): array {
            $movements = DebitRewardPointsAction::run(
                'user',
                (string) $payload['recipient_id'],
                (string) $payload['family'],
                (string) $payload['unit'],
                (int) $payload['points'],
                (string) $payload['operation_key'],
                'race-spend',
                null,
                CarbonImmutable::parse((string) $payload['occurred_at']),
            );

            return ['ok' => true, 'movements' => count($movements)];
        })(),
        'correction-reverse' => (function () use ($payload): array {
            $award = TransitionRewardAwardAction::run(
                (string) $payload['award_id'],
                RewardAwardStatus::Reversed,
                'user',
                (string) $payload['staff_id'],
                'staff',
                'race-correction',
                null,
                null,
                CarbonImmutable::parse((string) $payload['decided_at']),
            );

            return ['ok' => true, 'status' => $award->status->value];
        })(),
        'credit' => (function () use ($payload, $slot, $scoped): array {
            $p = $scoped('credit', $payload);
            $key = isset($p['operation_prefix'])
                ? (string) $p['operation_prefix'].'-slot-'.$slot
                : (string) $p['operation_key'];

            $movement = CreditRewardPointsAction::run(
                'user',
                (string) $p['recipient_id'],
                (string) $p['family'],
                (string) $p['unit'],
                (int) $p['points'],
                CarbonImmutable::parse((string) $p['available_at']),
                null,
                null,
                null,
                $key,
                'system',
                null,
                'race-credit',
                CarbonImmutable::parse((string) $p['available_at']),
            );

            return ['ok' => true, 'movement_id' => (string) $movement->getKey()];
        })(),
        'refund' => (function () use ($payload, $slot, $scoped): array {
            $p = $scoped('refund', $payload);
            $key = ($p['same_key'] ?? false) === true
                ? (string) $p['refund_prefix']
                : (string) $p['refund_prefix'].'-slot-'.$slot;

            $movements = RefundRewardPointsAction::run(
                $key,
                (string) $p['linked_aggregate'],
                (int) $p['points'],
                'race-refund',
                CarbonImmutable::parse((string) $p['occurred_at']),
            );

            return ['ok' => true, 'movements' => count($movements), 'aggregate' => $key];
        })(),
        'appeal-advance' => (function () use ($payload): array {
            $appeal = AdvanceRewardAppealAction::run(
                (string) $payload['appeal_id'],
                RewardAppealStatus::from((string) $payload['to']),
                'user',
                (string) $payload['reviewer_id'],
                'staff',
                'race-review',
                null,
                CarbonImmutable::parse((string) $payload['decided_at']),
            );

            return ['ok' => true, 'status' => $appeal->status->value, 'appeal_id' => (string) $appeal->getKey()];
        })(),
        'reserve-mismatch' => (function () use ($payload, $slot, $scoped): array {
            $p = $scoped('reserve-mismatch', $payload);
            $recipients = array_values((array) $p['recipient_ids']);

            $movements = ReserveRewardPointsAction::run(
                'user',
                (string) $recipients[$slot],
                (string) $p['family'],
                (string) $p['unit'],
                (int) $p['points'],
                (string) $p['reservation_key'],
                'race-reserve',
                CarbonImmutable::parse((string) $p['occurred_at']),
            );

            return ['ok' => true, 'movements' => count($movements), 'recipient_id' => (string) $recipients[$slot]];
        })(),
        'debit-mismatch' => (function () use ($payload, $slot, $scoped): array {
            $p = $scoped('debit-mismatch', $payload);
            $recipients = array_values((array) $p['recipient_ids']);

            $movements = DebitRewardPointsAction::run(
                'user',
                (string) $recipients[$slot],
                (string) $p['family'],
                (string) $p['unit'],
                (int) $p['points'],
                (string) $p['operation_key'],
                'race-debit',
                null,
                CarbonImmutable::parse((string) $p['occurred_at']),
            );

            return ['ok' => true, 'movements' => count($movements), 'recipient_id' => (string) $recipients[$slot]];
        })(),
        'refund-mismatch' => (function () use ($payload, $slot, $scoped): array {
            $p = $scoped('refund-mismatch', $payload);
            $linked = array_values((array) $p['linked_aggregates']);

            $movements = RefundRewardPointsAction::run(
                (string) $p['refund_key'],
                (string) $linked[$slot],
                (int) $p['points'],
                'race-refund',
                CarbonImmutable::parse((string) $p['occurred_at']),
            );

            return ['ok' => true, 'movements' => count($movements), 'linked' => (string) $linked[$slot]];
        })(),
        'approve' => (function () use ($payload, $scoped, $buildFact): array {
            $p = $scoped('approve', $payload);

            $reader = new FakeRewardSourceFactReader;
            $reader->seed($buildFact((array) $p['fact']));

            $award = (new TransitionRewardAwardAction(null, $reader))->handle(
                (string) $p['award_id'],
                RewardAwardStatus::Approved,
                'user',
                (string) $p['staff_id'],
                'staff',
                'race-approve',
                null,
                null,
                CarbonImmutable::parse((string) $p['decided_at']),
            );

            return ['ok' => true, 'status' => $award->status->value, 'award_id' => (string) $award->getKey()];
        })(),
        'overturn' => (function () use ($payload, $scoped, $buildFact): array {
            $p = $scoped('overturn', $payload);

            $reader = new FakeRewardSourceFactReader;
            $reader->seed($buildFact((array) $p['fact']));

            $evidence = isset($p['merit_override']) && is_array($p['merit_override'])
                ? ['merit_override' => array_values($p['merit_override'])]
                : null;

            $appeal = (new AdvanceRewardAppealAction($reader))->handle(
                (string) $p['appeal_id'],
                RewardAppealStatus::Overturned,
                'user',
                (string) $p['reviewer_id'],
                'admin',
                'race-overturn',
                $evidence,
                CarbonImmutable::parse((string) $p['decided_at']),
            );

            return ['ok' => true, 'status' => $appeal->status->value, 'appeal_id' => (string) $appeal->getKey()];
        })(),
        'fulfill' => (function () use ($payload, $scoped, $buildFact): array {
            $p = $scoped('fulfill', $payload);

            $reader = new FakeRewardSourceFactReader;

            foreach ((array) ($p['facts'] ?? [$p['fact']]) as $raw) {
                $reader->seed($buildFact((array) $raw));
            }

            $award = (new TransitionRewardAwardAction(null, $reader))->handle(
                (string) $p['award_id'],
                RewardAwardStatus::Fulfilled,
                'user',
                (string) $p['staff_id'],
                'staff',
                'race-fulfill',
                null,
                null,
                CarbonImmutable::parse((string) $p['decided_at']),
            );

            return ['ok' => true, 'status' => $award->status->value, 'award_id' => (string) $award->getKey()];
        })(),
        'invalidate' => (function () use ($payload, $scoped): array {
            $p = $scoped('invalidate', $payload);

            $cycle = InvalidateRewardMilestoneContributionAction::run(
                (string) $p['contribution_id'],
                'user',
                (string) $p['staff_id'],
                'staff',
                'race-correction',
                CarbonImmutable::parse((string) $p['revoked_at']),
            );

            return ['ok' => true, 'status' => $cycle->status->value, 'count' => $cycle->current_count];
        })(),
        'reverse' => (function () use ($payload, $scoped): array {
            $p = $scoped('reverse', $payload);

            // FIXTURE attestation only: scripted voidable keys exercise the
            // real reversal transaction — never host provider integration.
            $resolver = new FakeRewardReservationResolver(array_values((array) ($p['voidable'] ?? [])));

            $award = (new TransitionRewardAwardAction(null, null, $resolver))->handle(
                (string) $p['award_id'],
                RewardAwardStatus::Reversed,
                'user',
                (string) $p['staff_id'],
                'staff',
                'race-correction',
                null,
                null,
                CarbonImmutable::parse((string) $p['decided_at']),
            );

            return ['ok' => true, 'status' => $award->status->value, 'award_id' => (string) $award->getKey()];
        })(),
        'release' => (function () use ($payload, $scoped): array {
            $p = $scoped('release', $payload);

            $movements = ReleaseRewardPointsAction::run(
                (string) $p['reservation_key'],
                (string) ($p['reason'] ?? 'race-release'),
                CarbonImmutable::parse((string) $p['occurred_at']),
            );

            return ['ok' => true, 'movements' => count($movements)];
        })(),
        'capture' => (function () use ($payload, $scoped): array {
            $p = $scoped('capture', $payload);

            $movements = DebitRewardPointsAction::run(
                'user',
                (string) $p['recipient_id'],
                (string) $p['family'],
                (string) $p['unit'],
                (int) $p['points'],
                (string) $p['operation_key'],
                (string) ($p['reason'] ?? 'race-capture'),
                (string) $p['reservation_key'],
                CarbonImmutable::parse((string) $p['occurred_at']),
            );

            return ['ok' => true, 'movements' => count($movements)];
        })(),
    };

    echo json_encode($result, JSON_THROW_ON_ERROR)."\n";

    exit(0);
} catch (Throwable $exception) {
    echo json_encode([
        'ok' => false,
        'error' => substr($exception->getMessage(), 0, 300),
        'conflict' => str_contains($exception->getMessage(), 'cap_exceeded')
            || str_contains($exception->getMessage(), 'Insufficient available')
            || str_contains($exception->getMessage(), 'already used'),
    ], JSON_THROW_ON_ERROR)."\n";

    exit(0);
}
