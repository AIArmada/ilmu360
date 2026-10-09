<?php

use App\Actions\Rewards\AdvanceRewardAppealAction;
use App\Actions\Rewards\CreateRewardProgramAction;
use App\Actions\Rewards\CreditRewardPointsAction;
use App\Actions\Rewards\DebitRewardPointsAction;
use App\Actions\Rewards\EnrollRewardMemberAction;
use App\Actions\Rewards\EvaluateRewardEntitlementAction;
use App\Actions\Rewards\OpenRewardAppealAction;
use App\Actions\Rewards\PublishRewardProgramVersionAction;
use App\Actions\Rewards\RecordRewardMilestoneContributionAction;
use App\Actions\Rewards\RefundRewardPointsAction;
use App\Actions\Rewards\ReserveRewardPointsAction;
use App\Actions\Rewards\TransitionRewardAwardAction;
use App\Actions\Rewards\TransitionRewardProgramStatusAction;
use App\Enums\Rewards\RewardAppealStatus;
use App\Enums\Rewards\RewardAwardStatus;
use App\Enums\Rewards\RewardBeneficiaryRole;
use App\Enums\Rewards\RewardComponent;
use App\Enums\Rewards\RewardEligibleOutcome;
use App\Enums\Rewards\RewardProgramMode;
use App\Enums\Rewards\RewardProgramStatus;
use App\Enums\Rewards\RewardVerificationLevel;
use App\Models\RewardAdjustment;
use App\Models\RewardAppeal;
use App\Models\RewardAppealDecision;
use App\Models\RewardAward;
use App\Models\RewardCapCounter;
use App\Models\RewardMilestoneContribution;
use App\Models\RewardMilestoneCycle;
use App\Models\RewardPointLot;
use App\Models\RewardPointMovement;
use App\Models\RewardPointOperation;
use App\Models\RewardProgram;
use App\Models\RewardProgramVersion;
use App\Models\RewardSourceComponentClaim;
use App\Support\Rewards\RewardPointLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Symfony\Component\Process\Process;
use Tests\Feature\Rewards\Ledgers\Support\FakeRewardSourceFactReader;
use Tests\Feature\Rewards\Ledgers\Support\RewardLedgerFixtures;
use Tests\Feature\Rewards\Ledgers\Support\RewardLedgerWorkerDb;

function ledgerRaceReadyDir(string $driver, string $case): string
{
    $token = ParallelTesting::token();
    $dir = sys_get_temp_dir().'/rewards-ledger-race-'.($token !== false ? $token : 'serial').'-'.$driver.'-'.$case.'-'.getmypid();

    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    foreach (glob($dir.'/ready-*') ?: [] as $stale) {
        unlink($stale);
    }

    return $dir;
}

/**
 * @param  string|array{0: string, 1: string}  $modes
 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
 */
function runLedgerRacePair(string $driver, string $database, string|array $modes, array $payload, string $case): array
{
    $readyDir = ledgerRaceReadyDir($driver, $case);
    file_put_contents($readyDir.'/payload.json', json_encode($payload, JSON_THROW_ON_ERROR));

    $configs = RewardLedgerWorkerDb::driverConfigs();
    $username = (string) $configs[$driver]['username'];
    $password = (string) $configs[$driver]['password'];
    $port = $driver === 'pgsql' ? 45432 : 43306;

    $worker = __DIR__.'/RewardLedgerRaceWorker.php';

    $make = fn (int $slot): Process => new Process([
        PHP_BINARY, $worker,
        '--driver='.$driver,
        '--host=127.0.0.1',
        '--port='.$port,
        '--database='.$database,
        '--username='.$username,
        '--password='.$password,
        '--mode='.(is_array($modes) ? $modes[$slot] : $modes),
        '--ready-dir='.$readyDir,
        '--slot='.$slot,
    ], timeout: 120);

    $first = $make(0);
    $second = $make(1);
    $first->start();
    $second->start();
    $first->wait();
    $second->wait();

    $decode = static function (Process $process): array {
        $exit = $process->getExitCode();
        $output = trim($process->getOutput());

        if ($output === '') {
            return ['ok' => false, 'exit' => $exit, 'error' => substr(trim($process->getErrorOutput()), 0, 300)];
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($output, true);

        if (! is_array($decoded)) {
            return ['ok' => false, 'exit' => $exit, 'error' => 'Worker output was not JSON.'];
        }

        $decoded['exit'] = $exit;

        return $decoded;
    };

    return [$decode($first), $decode($second)];
}

/**
 * @param  array<string, mixed>  $a
 * @param  array<string, mixed>  $b
 */
function assertLedgerWorkersExited(array $a, array $b): void
{
    Assert::assertSame(0, $a['exit'] ?? null, 'Worker A failed: '.($a['error'] ?? json_encode($a)));
    Assert::assertSame(0, $b['exit'] ?? null, 'Worker B failed: '.($b['error'] ?? json_encode($b)));
}

/**
 * @return array<string, mixed>
 */
function raceFactPayload(string $source, string $recipientId): array
{
    return [
        'source_business_key' => $source,
        'outcome' => 'event_checkin',
        'category' => null,
        'occurred_at' => '2026-06-12T00:00:00Z',
        'verification' => 'staff_verified',
        'is_first_time' => true,
        'actor_id' => (string) Str::uuid(),
        'sharer_id' => $recipientId,
        'actor_role' => 'participant',
        'is_claimed_identity' => true,
        'is_attributed' => true,
        'is_ambiguous_device' => false,
        'is_bot' => false,
        'is_cancelled' => false,
        'is_valid' => true,
        'fraud_status' => 'clear',
        'correction_status' => 'current',
        'correction_id' => null,
    ];
}

/**
 * @return array{0: RewardProgram, 1: RewardProgramVersion, 2: string}
 */
function raceSeedProgram(string $family, string $case, ?array $caps = null, array $compatible = []): array
{
    $program = CreateRewardProgramAction::run($family.'-'.$case.'-'.Str::lower(Str::random(4)), 'Race program', RewardProgramMode::Points, 'platform', null, 'race-test');

    $rules = RewardLedgerFixtures::rules();
    $rules['stacking'] = ['compatible_with' => $compatible];

    if ($caps !== null) {
        $rules['caps'] = $caps;
    }

    $at = CarbonImmutable::parse('2026-06-01T00:00:00Z');
    $version = PublishRewardProgramVersionAction::run($program, $at, $rules, RewardLedgerFixtures::terms(), null, null, null, $at);

    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Ready);
    TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active);

    $memberId = (string) Str::uuid();
    EnrollRewardMemberAction::run($program, 'user', $memberId, $version, 'member-portal', CarbonImmutable::parse('2026-06-10T00:00:00Z'));

    return [$program->refresh(), $version, $memberId];
}

it('keeps one canonical award under concurrent duplicate evaluations', function (string $driver) {
    [$database, $previous] = RewardLedgerWorkerDb::setUp($driver, 'evaldup');

    try {
        [$program, , $memberId] = raceSeedProgram('race-evaldup', 'e1');

        $payload = [
            'program_ids' => [(string) $program->getKey()],
            'component' => 'points',
            'recipient_id' => $memberId,
            'role' => 'recipient',
            'source' => 'src-race-dup-1',
            'decision' => '2026-06-15T00:00:00Z',
            'fact' => raceFactPayload('src-race-dup-1', $memberId),
        ];

        [$a, $b] = runLedgerRacePair($driver, $database, 'evaluate', $payload, 'evaldup');

        assertLedgerWorkersExited($a, $b);

        Assert::assertTrue($a['ok'] ?? false, 'Evaldup worker A failed: '.($a['error'] ?? json_encode($a)));
        Assert::assertTrue($b['ok'] ?? false, 'Evaldup worker B failed: '.($b['error'] ?? json_encode($b)));

        $duplicates = array_filter([$a, $b], static fn (array $r): bool => ($r['duplicate'] ?? false) === true);

        expect($duplicates)->toHaveCount(1)
            ->and(RewardAward::query()->count())->toBe(1)
            ->and(RewardSourceComponentClaim::query()->count())->toBe(1)
            ->and(RewardAward::query()->firstOrFail()->status)->toBe(RewardAwardStatus::Approved);
    } finally {
        RewardLedgerWorkerDb::tearDown($driver, $database, $previous);
    }
})->with(['pgsql', 'mariadb']);

it('freezes the first award when concurrent evaluations race a version switch', function (string $driver) {
    [$database, $previous] = RewardLedgerWorkerDb::setUp($driver, 'evalver');

    try {
        [$program, $v1, $memberId] = raceSeedProgram('race-evalver', 'e2');

        $reader = new FakeRewardSourceFactReader;
        $reader->seed(RewardLedgerFixtures::fact('src-race-ver-1', $memberId));

        $first = (new EvaluateRewardEntitlementAction($reader))->handle(
            $program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient,
            'src-race-ver-1', CarbonImmutable::parse('2026-06-15T00:00:00Z'),
        );

        expect($first->award->amount_snapshot['points'])->toBe(10);

        $rules = RewardLedgerFixtures::rules();
        $rules['components'] = [['type' => 'points', 'points' => 50, 'unit' => 'pts']];
        $terms = RewardLedgerFixtures::terms();
        $terms['version_label'] = 'draft-2';
        $effective = CarbonImmutable::parse('2026-07-01T00:00:00Z');
        $v2 = PublishRewardProgramVersionAction::run($program, $effective, $rules, $terms, 'raise', null, null, $effective);
        EnrollRewardMemberAction::run($program, 'user', $memberId, $v2, 'member-portal', CarbonImmutable::parse('2026-07-02T00:00:00Z'));

        $payload = [
            'program_ids' => [(string) $program->getKey()],
            'component' => 'points',
            'recipient_id' => $memberId,
            'role' => 'recipient',
            'source' => 'src-race-ver-1',
            'decision' => '2026-07-05T00:00:00Z',
            'fact' => raceFactPayload('src-race-ver-1', $memberId),
        ];

        [$a, $b] = runLedgerRacePair($driver, $database, 'evaluate', $payload, 'evalver');

        assertLedgerWorkersExited($a, $b);

        expect($a['exit'] ?? null)->toBe(0)
            ->and($b['exit'] ?? null)->toBe(0)
            ->and($a['duplicate'] ?? false)->toBeTrue()
            ->and($b['duplicate'] ?? false)->toBeTrue()
            ->and(RewardAward::query()->count())->toBe(1)
            ->and(RewardAward::query()->firstOrFail()->policy_snapshot['policy_version'])->toBe(1);
    } finally {
        RewardLedgerWorkerDb::tearDown($driver, $database, $previous);
    }
})->with(['pgsql', 'mariadb']);

it('serializes two-family exclusivity races to a single claim', function (string $driver) {
    [$database, $previous] = RewardLedgerWorkerDb::setUp($driver, 'excl');

    try {
        [$programA, , $memberId] = raceSeedProgram('race-excla', 'x1');
        [$programB] = raceSeedProgram('race-exclb', 'x1');

        $versionB = $programB->versions()->orderBy('version')->firstOrFail();
        EnrollRewardMemberAction::run($programB, 'user', $memberId, $versionB, 'member-portal', CarbonImmutable::parse('2026-06-10T00:00:00Z'));

        $payload = [
            'program_ids' => [(string) $programA->getKey(), (string) $programB->getKey()],
            'component' => 'points',
            'recipient_id' => $memberId,
            'role' => 'recipient',
            'source' => 'src-race-excl-1',
            'decision' => '2026-06-15T00:00:00Z',
            'fact' => raceFactPayload('src-race-excl-1', $memberId),
        ];

        [$a, $b] = runLedgerRacePair($driver, $database, 'evaluate', $payload, 'excl');

        assertLedgerWorkersExited($a, $b);

        Assert::assertTrue($a['ok'] ?? false, 'Excl worker A failed: '.($a['error'] ?? json_encode($a)));
        Assert::assertTrue($b['ok'] ?? false, 'Excl worker B failed: '.($b['error'] ?? json_encode($b)));

        $outcomes = [$a['outcome'] ?? null, $b['outcome'] ?? null];
        sort($outcomes);

        expect($outcomes)->toBe(['awarded', 'rejected'])
            ->and(RewardSourceComponentClaim::query()->count())->toBe(1)
            ->and(RewardAward::query()->count())->toBe(2);
    } finally {
        RewardLedgerWorkerDb::tearDown($driver, $database, $previous);
    }
})->with(['pgsql', 'mariadb']);

it('stacks compatible families concurrently without losing either award', function (string $driver) {
    [$database, $previous] = RewardLedgerWorkerDb::setUp($driver, 'compat');

    try {
        $programA = CreateRewardProgramAction::run('race-compa-c1-'.$driver, 'Race program', RewardProgramMode::Points, 'platform', null, 'race-test');
        $programB = CreateRewardProgramAction::run('race-compb-c1-'.$driver, 'Race program', RewardProgramMode::Points, 'platform', null, 'race-test');

        $at = CarbonImmutable::parse('2026-06-01T00:00:00Z');

        foreach ([$programA, $programB] as $program) {
            $other = $program->is($programA) ? $programB->family_key : $programA->family_key;
            $rules = RewardLedgerFixtures::rules();
            $rules['stacking'] = ['compatible_with' => [$other]];
            $rules['caps'] = [['scope' => 'recipient_program_day', 'limit' => 10]];
            $version = PublishRewardProgramVersionAction::run($program, $at, $rules, RewardLedgerFixtures::terms(), null, null, null, $at);
            TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Ready);
            TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active);
        }

        $memberId = (string) Str::uuid();

        foreach ([$programA, $programB] as $program) {
            $version = $program->versions()->orderBy('version')->firstOrFail();
            EnrollRewardMemberAction::run($program, 'user', $memberId, $version, 'member-portal', CarbonImmutable::parse('2026-06-10T00:00:00Z'));
        }

        $payload = [
            'program_ids' => [(string) $programA->getKey(), (string) $programB->getKey()],
            'component' => 'points',
            'recipient_id' => $memberId,
            'role' => 'recipient',
            'source' => 'src-race-comp-1',
            'decision' => '2026-06-15T00:00:00Z',
            'fact' => raceFactPayload('src-race-comp-1', $memberId),
        ];

        [$a, $b] = runLedgerRacePair($driver, $database, 'evaluate', $payload, 'compat');

        assertLedgerWorkersExited($a, $b);

        Assert::assertSame('awarded', $a['outcome'] ?? null, 'Compat worker A failed: '.($a['error'] ?? json_encode($a)));
        Assert::assertSame('awarded', $b['outcome'] ?? null, 'Compat worker B failed: '.($b['error'] ?? json_encode($b)));

        expect($a['exit'] ?? null)->toBe(0)
            ->and($b['exit'] ?? null)->toBe(0)
            ->and(RewardAward::query()->count())->toBe(2)
            ->and(RewardSourceComponentClaim::query()->count())->toBe(2);
    } finally {
        RewardLedgerWorkerDb::tearDown($driver, $database, $previous);
    }
})->with(['pgsql', 'mariadb']);

it('never overspends a cap under concurrent evaluations', function (string $driver) {
    [$database, $previous] = RewardLedgerWorkerDb::setUp($driver, 'caprace');

    try {
        [$program, , $memberId] = raceSeedProgram('race-cap', 'c1', [
            ['scope' => 'recipient_source_component', 'limit' => 1],
            ['scope' => 'program_total', 'limit' => 1],
        ]);

        $fact = raceFactPayload('src-race-cap-a', $memberId);

        $payload = [
            'program_id' => (string) $program->getKey(),
            'component' => 'points',
            'recipient_id' => $memberId,
            'role' => 'recipient',
            'sources' => ['src-race-cap-a', 'src-race-cap-b'],
            'decision' => '2026-06-15T00:00:00Z',
            'fact' => $fact,
        ];

        [$a, $b] = runLedgerRacePair($driver, $database, 'cap', $payload, 'caprace');

        assertLedgerWorkersExited($a, $b);

        $wins = array_filter([$a, $b], static fn (array $r): bool => ($r['ok'] ?? false) === true);

        expect($wins)->toHaveCount(1)
            ->and(RewardAward::query()->count())->toBe(1);

        $loser = ($a['ok'] ?? false) === true ? $b : $a;

        expect($loser['conflict'] ?? false)->toBeTrue()
            ->and(RewardCapCounter::query()->where('scope', 'program_total')->firstOrFail()->consumed)->toBe(1);
    } finally {
        RewardLedgerWorkerDb::tearDown($driver, $database, $previous);
    }
})->with(['pgsql', 'mariadb']);

it('serializes simultaneous point reserves without overspending', function (string $driver) {
    [$database, $previous] = RewardLedgerWorkerDb::setUp($driver, 'rsvrace');

    try {
        $recipient = (string) Str::uuid();
        $family = 'race-rsv-'.$driver;

        CreditRewardPointsAction::run('user', $recipient, $family, 'pts', 100, CarbonImmutable::parse('2026-06-01T00:00:00Z'), null, null, null, 'op:race-rsv-credit', 'system', null, 'race-credit', CarbonImmutable::parse('2026-06-01T00:00:00Z'));

        $payload = [
            'recipient_id' => $recipient,
            'family' => $family,
            'unit' => 'pts',
            'points' => 60,
            'reservation_prefix' => 'rsv:race-1',
            'occurred_at' => '2026-06-05T00:00:00Z',
        ];

        [$a, $b] = runLedgerRacePair($driver, $database, 'reserve', $payload, 'rsvrace');

        assertLedgerWorkersExited($a, $b);

        $wins = array_filter([$a, $b], static fn (array $r): bool => ($r['ok'] ?? false) === true);

        expect($wins)->toHaveCount(1);

        $buckets = RewardPointLedger::balancesFor('user', $recipient, $family, 'pts', CarbonImmutable::parse('2026-06-06T00:00:00Z'));

        expect($buckets->reserved)->toBe(60)
            ->and($buckets->available)->toBe(40);
    } finally {
        RewardLedgerWorkerDb::tearDown($driver, $database, $previous);
    }
})->with(['pgsql', 'mariadb']);

it('serializes simultaneous point debits without going negative', function (string $driver) {
    [$database, $previous] = RewardLedgerWorkerDb::setUp($driver, 'debrace');

    try {
        $recipient = (string) Str::uuid();
        $family = 'race-deb-'.$driver;

        CreditRewardPointsAction::run('user', $recipient, $family, 'pts', 100, CarbonImmutable::parse('2026-06-01T00:00:00Z'), null, null, null, 'op:race-deb-credit', 'system', null, 'race-credit', CarbonImmutable::parse('2026-06-01T00:00:00Z'));

        $payload = [
            'recipient_id' => $recipient,
            'family' => $family,
            'unit' => 'pts',
            'points' => 60,
            'operation_prefix' => 'spend:race-1',
            'occurred_at' => '2026-06-05T00:00:00Z',
        ];

        [$a, $b] = runLedgerRacePair($driver, $database, 'debit', $payload, 'debrace');

        assertLedgerWorkersExited($a, $b);

        $wins = array_filter([$a, $b], static fn (array $r): bool => ($r['ok'] ?? false) === true);

        expect($wins)->toHaveCount(1);

        $buckets = RewardPointLedger::balancesFor('user', $recipient, $family, 'pts', CarbonImmutable::parse('2026-06-06T00:00:00Z'));

        expect($buckets->spent)->toBe(60)
            ->and($buckets->available)->toBe(40);
    } finally {
        RewardLedgerWorkerDb::tearDown($driver, $database, $previous);
    }
})->with(['pgsql', 'mariadb']);

it('expires a lot exactly once under concurrent expiry runs', function (string $driver) {
    [$database, $previous] = RewardLedgerWorkerDb::setUp($driver, 'exprace');

    try {
        $recipient = (string) Str::uuid();
        $family = 'race-exp-'.$driver;

        CreditRewardPointsAction::run('user', $recipient, $family, 'pts', 50, CarbonImmutable::parse('2026-06-01T00:00:00Z'), CarbonImmutable::parse('2026-07-01T00:00:00Z'), null, null, 'op:race-exp-credit', 'system', null, 'race-credit', CarbonImmutable::parse('2026-06-01T00:00:00Z'));

        $payload = [
            'recipient_id' => $recipient,
            'family' => $family,
            'unit' => 'pts',
            'as_of' => '2026-07-02T00:00:00Z',
        ];

        [$a, $b] = runLedgerRacePair($driver, $database, 'expire', $payload, 'exprace');

        assertLedgerWorkersExited($a, $b);

        Assert::assertTrue($a['ok'] ?? false, 'Expire worker A failed: '.($a['error'] ?? json_encode($a)));
        Assert::assertTrue($b['ok'] ?? false, 'Expire worker B failed: '.($b['error'] ?? json_encode($b)));

        expect(RewardPointMovement::query()->where('kind', 'expire')->count())->toBe(1);

        $buckets = RewardPointLedger::balancesFor('user', $recipient, $family, 'pts', CarbonImmutable::parse('2026-07-03T00:00:00Z'));

        expect($buckets->expired)->toBe(50)
            ->and($buckets->available)->toBe(0);
    } finally {
        RewardLedgerWorkerDb::tearDown($driver, $database, $previous);
    }
})->with(['pgsql', 'mariadb']);

it('achieves a milestone exactly once under concurrent contributions', function (string $driver) {
    [$database, $previous] = RewardLedgerWorkerDb::setUp($driver, 'milerace');

    try {
        $program = CreateRewardProgramAction::run('race-mile-m1-'.Str::lower(Str::random(4)), 'Race program', RewardProgramMode::Points, 'platform', null, 'race-test');
        $rules = RewardLedgerFixtures::rules();
        $rules['milestones'] = [['key' => 'race-two', 'target_count' => 2, 'window_days' => 30, 'reward' => ['type' => 'points', 'points' => 25, 'unit' => 'pts']]];
        $at = CarbonImmutable::parse('2026-06-01T00:00:00Z');
        $version = PublishRewardProgramVersionAction::run($program, $at, $rules, RewardLedgerFixtures::terms(), null, null, null, $at);
        TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Ready);
        TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active);
        $recipient = (string) Str::uuid();
        EnrollRewardMemberAction::run($program, 'user', $recipient, $version, 'member-portal', CarbonImmutable::parse('2026-06-10T00:00:00Z'));
        $program = $program->refresh();

        $factA = raceFactPayload('src-race-mile-a', $recipient);
        $factA['occurred_at'] = '2026-06-10T00:00:00Z';
        $factB = raceFactPayload('src-race-mile-b', $recipient);
        $factB['occurred_at'] = '2026-06-10T00:00:00Z';

        $payload = [
            'recipient_id' => $recipient,
            'family' => $program->family_key,
            'milestone_key' => 'race-two',
            'target_count' => 2,
            'window_days' => 30,
            'component' => 'points',
            'reward' => ['points' => 25, 'unit' => 'pts'],
            'sources' => ['src-race-mile-a', 'src-race-mile-b'],
            'facts' => [$factA, $factB],
            'outcome' => 'event_checkin',
            'occurred_at' => '2026-06-10T00:00:00Z',
            'program_id' => (string) $program->getKey(),
        ];

        [$a, $b] = runLedgerRacePair($driver, $database, 'milestone', $payload, 'milerace');

        assertLedgerWorkersExited($a, $b);

        expect($a['exit'] ?? null)->toBe(0)
            ->and($b['exit'] ?? null)->toBe(0);

        Assert::assertTrue($a['ok'] ?? false, 'Milestone worker A failed: '.($a['error'] ?? json_encode($a)));
        Assert::assertTrue($b['ok'] ?? false, 'Milestone worker B failed: '.($b['error'] ?? json_encode($b)));

        expect(RewardAward::query()->count())->toBe(1)
            ->and(RewardMilestoneCycle::query()->firstOrFail()->current_count)->toBe(2);

        $achieved = array_filter([$a, $b], static fn (array $r): bool => ($r['achieved'] ?? false) === true);

        expect($achieved)->toHaveCount(1);
    } finally {
        RewardLedgerWorkerDb::tearDown($driver, $database, $previous);
    }
})->with(['pgsql', 'mariadb']);

it('keeps buckets coherent when correction races spending', function (string $driver) {
    [$database, $previous] = RewardLedgerWorkerDb::setUp($driver, 'corrrace');

    try {
        [$program, , $memberId] = raceSeedProgram('race-corr', 'r1');

        $reader = new FakeRewardSourceFactReader;
        $reader->seed(RewardLedgerFixtures::fact('src-race-corr-1', $memberId));

        $result = (new EvaluateRewardEntitlementAction($reader))->handle(
            $program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient,
            'src-race-corr-1', CarbonImmutable::parse('2026-06-15T00:00:00Z'),
        );

        $staff = RewardLedgerFixtures::staffId();

        (new TransitionRewardAwardAction(null, $reader))->handle($result->award, RewardAwardStatus::Fulfilled, 'user', $staff, 'staff', 'fulfill', null, null, CarbonImmutable::parse('2026-06-16T00:00:00Z'));

        expect(RewardPointLot::query()->count())->toBe(1);

        $payload = [
            'recipient_id' => $memberId,
            'family' => $program->family_key,
            'unit' => 'pts',
            'points' => 10,
            'operation_key' => 'spend:race-corr-1',
            'occurred_at' => '2026-06-17T00:00:00Z',
            'award_id' => (string) $result->award->getKey(),
            'staff_id' => $staff,
            'decided_at' => '2026-06-17T00:00:00Z',
        ];

        [$a, $b] = runLedgerRacePair($driver, $database, ['correction-debit', 'correction-reverse'], $payload, 'corrrace');

        assertLedgerWorkersExited($a, $b);

        Assert::assertTrue($b['ok'] ?? false, 'Correction worker B failed: '.($b['error'] ?? json_encode($b)));

        expect(RewardAward::query()->firstOrFail()->status)->toBe(RewardAwardStatus::Reversed);

        $buckets = RewardPointLedger::balancesFor('user', $memberId, $program->family_key, 'pts', CarbonImmutable::parse('2026-06-18T00:00:00Z'));

        expect($buckets->available)->toBe(0)
            ->and($buckets->spent + $buckets->reserved + $buckets->expired)->toBeLessThanOrEqual(10);

        $debits = (int) RewardPointMovement::query()->where('kind', 'debit')->sum('delta');
        $clawbacks = (int) RewardPointMovement::query()->where('kind', 'adjust')->where('delta', '<', 0)->sum('delta');

        expect($debits - $clawbacks)->toBe(10);
    } finally {
        RewardLedgerWorkerDb::tearDown($driver, $database, $previous);
    }
})->with(['pgsql', 'mariadb']);

it('collapses concurrent same-key credits onto one lot', function (string $driver) {
    [$database, $previous] = RewardLedgerWorkerDb::setUp($driver, 'creditreplay');

    try {
        $recipient = (string) Str::uuid();
        $family = 'race-crep-'.$driver;

        $payload = [
            'recipient_id' => $recipient,
            'family' => $family,
            'unit' => 'pts',
            'points' => 40,
            'available_at' => '2026-06-01T00:00:00Z',
            'operation_key' => 'op:race-same-credit',
        ];

        [$a, $b] = runLedgerRacePair($driver, $database, 'credit', $payload, 'creditreplay');

        assertLedgerWorkersExited($a, $b);

        Assert::assertTrue($a['ok'] ?? false, 'Credit replay worker A failed: '.($a['error'] ?? json_encode($a)));
        Assert::assertTrue($b['ok'] ?? false, 'Credit replay worker B failed: '.($b['error'] ?? json_encode($b)));

        expect(($a['movement_id'] ?? null))->toBe($b['movement_id'] ?? null)
            ->and(RewardPointLot::query()->count())->toBe(1)
            ->and(RewardPointMovement::query()->count())->toBe(1);
    } finally {
        RewardLedgerWorkerDb::tearDown($driver, $database, $previous);
    }
})->with(['pgsql', 'mariadb']);

it('collapses concurrent same-key reserves onto one reservation', function (string $driver) {
    [$database, $previous] = RewardLedgerWorkerDb::setUp($driver, 'rsvreplay');

    try {
        $recipient = (string) Str::uuid();
        $family = 'race-rrp-'.$driver;

        CreditRewardPointsAction::run('user', $recipient, $family, 'pts', 100, CarbonImmutable::parse('2026-06-01T00:00:00Z'), null, null, null, 'op:race-rrp-credit', 'system', null, 'race-credit', CarbonImmutable::parse('2026-06-01T00:00:00Z'));

        $payload = [
            'recipient_id' => $recipient,
            'family' => $family,
            'unit' => 'pts',
            'points' => 60,
            'reservation_prefix' => 'rsv:race-same-1',
            'same_key' => true,
            'occurred_at' => '2026-06-05T00:00:00Z',
        ];

        [$a, $b] = runLedgerRacePair($driver, $database, 'reserve', $payload, 'rsvreplay');

        assertLedgerWorkersExited($a, $b);

        Assert::assertTrue($a['ok'] ?? false, 'Reserve replay worker A failed: '.($a['error'] ?? json_encode($a)));
        Assert::assertTrue($b['ok'] ?? false, 'Reserve replay worker B failed: '.($b['error'] ?? json_encode($b)));

        expect(($a['aggregate'] ?? null))->toBe($b['aggregate'] ?? null);

        $buckets = RewardPointLedger::balancesFor('user', $recipient, $family, 'pts', CarbonImmutable::parse('2026-06-06T00:00:00Z'));

        expect($buckets->reserved)->toBe(60)
            ->and($buckets->available)->toBe(40);
    } finally {
        RewardLedgerWorkerDb::tearDown($driver, $database, $previous);
    }
})->with(['pgsql', 'mariadb']);

it('leaves no partial movements when point operations fail on real drivers', function (string $driver) {
    [$database, $previous] = RewardLedgerWorkerDb::setUp($driver, 'atomic');

    try {
        $recipient = (string) Str::uuid();
        $family = 'race-atomic-'.$driver;

        CreditRewardPointsAction::run('user', $recipient, $family, 'pts', 30, CarbonImmutable::parse('2026-06-01T00:00:00Z'), null, null, null, 'op:race-atomic-credit', 'system', null, 'race-credit', CarbonImmutable::parse('2026-06-01T00:00:00Z'));

        expect(fn () => ReserveRewardPointsAction::run('user', $recipient, $family, 'pts', 31, 'rsv:race-atomic-1', 'over', CarbonImmutable::parse('2026-06-05T00:00:00Z')))
            ->toThrow(InvalidArgumentException::class, 'Insufficient available');

        expect(fn () => DebitRewardPointsAction::run('user', $recipient, $family, 'pts', 31, 'spend:race-atomic-1', 'over', null, CarbonImmutable::parse('2026-06-05T00:00:00Z')))
            ->toThrow(InvalidArgumentException::class, 'Insufficient available');

        expect(RewardPointMovement::query()->count())->toBe(1);

        $buckets = RewardPointLedger::balancesFor('user', $recipient, $family, 'pts', CarbonImmutable::parse('2026-06-06T00:00:00Z'));

        expect($buckets->available)->toBe(30)
            ->and($buckets->total())->toBe(30);
    } finally {
        RewardLedgerWorkerDb::tearDown($driver, $database, $previous);
    }
})->with(['pgsql', 'mariadb']);

it('collapses concurrent same-key refunds onto one allocation', function (string $driver) {
    [$database, $previous] = RewardLedgerWorkerDb::setUp($driver, 'refundreplay');

    try {
        $recipient = (string) Str::uuid();
        $family = 'race-rref-'.$driver;

        CreditRewardPointsAction::run('user', $recipient, $family, 'pts', 50, CarbonImmutable::parse('2026-06-01T00:00:00Z'), null, null, null, 'op:race-rref-credit', 'system', null, 'race-credit', CarbonImmutable::parse('2026-06-01T00:00:00Z'));
        DebitRewardPointsAction::run('user', $recipient, $family, 'pts', 20, 'spend:race-rref-1', 'spend', null, CarbonImmutable::parse('2026-06-05T00:00:00Z'));

        $payload = [
            'linked_aggregate' => 'spend:race-rref-1',
            'points' => 12,
            'refund_prefix' => 'refund:race-same-1',
            'same_key' => true,
            'occurred_at' => '2026-06-06T00:00:00Z',
        ];

        [$a, $b] = runLedgerRacePair($driver, $database, 'refund', $payload, 'refundreplay');

        assertLedgerWorkersExited($a, $b);

        Assert::assertTrue($a['ok'] ?? false, 'Refund replay worker A failed: '.($a['error'] ?? json_encode($a)));
        Assert::assertTrue($b['ok'] ?? false, 'Refund replay worker B failed: '.($b['error'] ?? json_encode($b)));

        expect(($a['aggregate'] ?? null))->toBe($b['aggregate'] ?? null)
            ->and(RewardPointLot::query()->count())->toBe(2)
            ->and(RewardPointMovement::query()->where('kind', 'refund')->count())->toBe(1);

        $buckets = RewardPointLedger::balancesFor('user', $recipient, $family, 'pts', CarbonImmutable::parse('2026-06-07T00:00:00Z'));

        expect($buckets->available)->toBe(42)
            ->and($buckets->spent)->toBe(20);
    } finally {
        RewardLedgerWorkerDb::tearDown($driver, $database, $previous);
    }
})->with(['pgsql', 'mariadb']);

it('bounds concurrent distinct partial refunds by the debited total', function (string $driver) {
    [$database, $previous] = RewardLedgerWorkerDb::setUp($driver, 'refundcap');

    try {
        $recipient = (string) Str::uuid();
        $family = 'race-rfc-'.$driver;

        CreditRewardPointsAction::run('user', $recipient, $family, 'pts', 50, CarbonImmutable::parse('2026-06-01T00:00:00Z'), null, null, null, 'op:race-rfc-credit', 'system', null, 'race-credit', CarbonImmutable::parse('2026-06-01T00:00:00Z'));
        DebitRewardPointsAction::run('user', $recipient, $family, 'pts', 20, 'spend:race-rfc-1', 'spend', null, CarbonImmutable::parse('2026-06-05T00:00:00Z'));

        $payload = [
            'linked_aggregate' => 'spend:race-rfc-1',
            'points' => 15,
            'refund_prefix' => 'refund:race-cap-1',
            'same_key' => false,
            'occurred_at' => '2026-06-06T00:00:00Z',
        ];

        [$a, $b] = runLedgerRacePair($driver, $database, 'refund', $payload, 'refundcap');

        assertLedgerWorkersExited($a, $b);

        $wins = array_filter([$a, $b], static fn (array $r): bool => ($r['ok'] ?? false) === true);

        expect($wins)->toHaveCount(1);

        $refunded = (int) RewardPointMovement::query()->where('kind', 'refund')->sum('delta');

        expect($refunded)->toBe(15);
    } finally {
        RewardLedgerWorkerDb::tearDown($driver, $database, $previous);
    }
})->with(['pgsql', 'mariadb']);

it('serializes concurrent appeal advances without duplicate decisions', function (string $driver) {
    [$database, $previous] = RewardLedgerWorkerDb::setUp($driver, 'appealrace');

    try {
        [$program, , $memberId] = raceSeedProgram('race-appeal', 'a1');

        $reader = new FakeRewardSourceFactReader;
        $reader->seed(RewardLedgerFixtures::fact('src-race-appeal-1', $memberId));

        $result = (new EvaluateRewardEntitlementAction($reader))->handle(
            $program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient,
            'src-race-appeal-1', CarbonImmutable::parse('2026-06-15T00:00:00Z'),
        );

        $opened = OpenRewardAppealAction::run($result->award, 'user', $memberId, 'member', 'please-recheck', null, CarbonImmutable::parse('2026-06-20T00:00:00Z'));
        $reviewer = RewardLedgerFixtures::staffId();

        $payload = [
            'appeal_id' => (string) $opened->getKey(),
            'to' => 'in_review',
            'reviewer_id' => $reviewer,
            'decided_at' => '2026-06-21T00:00:00Z',
        ];

        [$a, $b] = runLedgerRacePair($driver, $database, 'appeal-advance', $payload, 'appealrace');

        assertLedgerWorkersExited($a, $b);

        Assert::assertTrue($a['ok'] ?? false, 'Appeal worker A failed: '.($a['error'] ?? json_encode($a)));
        Assert::assertTrue($b['ok'] ?? false, 'Appeal worker B failed: '.($b['error'] ?? json_encode($b)));

        expect(RewardAppeal::query()->whereKey($opened->getKey())->firstOrFail()->status->value)->toBe('in_review')
            ->and(RewardAppealDecision::query()->where('appeal_id', $opened->getKey())->count())->toBe(2);
    } finally {
        RewardLedgerWorkerDb::tearDown($driver, $database, $previous);
    }
})->with(['pgsql', 'mariadb']);

it('refuses concurrent mismatched reservation reuse across disjoint recipients', function (string $driver) {
    [$database, $previous] = RewardLedgerWorkerDb::setUp($driver, 'rsvmismatch');

    try {
        $first = (string) Str::uuid();
        $second = (string) Str::uuid();
        $family = 'race-rmm-'.$driver;

        foreach ([$first, $second] as $index => $recipient) {
            CreditRewardPointsAction::run('user', $recipient, $family, 'pts', 40, CarbonImmutable::parse('2026-06-01T00:00:00Z'), CarbonImmutable::parse('2026-07-10T00:00:00Z'), null, null, 'op:race-rmm-credit-'.$index.'a', 'system', null, 'race-credit', CarbonImmutable::parse('2026-06-01T00:00:00Z'));
            CreditRewardPointsAction::run('user', $recipient, $family, 'pts', 40, CarbonImmutable::parse('2026-06-01T00:00:00Z'), CarbonImmutable::parse('2026-09-10T00:00:00Z'), null, null, 'op:race-rmm-credit-'.$index.'b', 'system', null, 'race-credit', CarbonImmutable::parse('2026-06-01T00:00:00Z'));
        }

        $payload = [
            'recipient_ids' => [$first, $second],
            'family' => $family,
            'unit' => 'pts',
            'points' => 50,
            'reservation_key' => 'rsv:race-mismatch-1',
            'occurred_at' => '2026-06-05T00:00:00Z',
        ];

        [$a, $b] = runLedgerRacePair($driver, $database, 'reserve-mismatch', $payload, 'rsvmismatch');

        assertLedgerWorkersExited($a, $b);

        $wins = array_filter([$a, $b], static fn (array $r): bool => ($r['ok'] ?? false) === true);

        expect($wins)->toHaveCount(1);

        $winner = ($a['ok'] ?? false) === true ? $a : $b;
        $loser = ($a['ok'] ?? false) === true ? $b : $a;

        expect($loser['error'] ?? '')->toContain('different recipient');

        $operation = RewardPointOperation::query()->where('kind', 'reserve')->where('operation_key', 'rsv:race-mismatch-1')->firstOrFail();

        expect(RewardPointOperation::query()->where('kind', 'reserve')->count())->toBe(1)
            ->and((string) $operation->recipient_id)->toBe($winner['recipient_id'] ?? null);

        $winnerId = (string) $winner['recipient_id'];
        $loserId = $winnerId === $first ? $second : $first;

        $winnerBuckets = RewardPointLedger::balancesFor('user', $winnerId, $family, 'pts', CarbonImmutable::parse('2026-06-06T00:00:00Z'));
        $loserBuckets = RewardPointLedger::balancesFor('user', $loserId, $family, 'pts', CarbonImmutable::parse('2026-06-06T00:00:00Z'));

        expect($winnerBuckets->reserved)->toBe(50)
            ->and($winnerBuckets->available)->toBe(30)
            ->and($loserBuckets->reserved)->toBe(0)
            ->and($loserBuckets->available)->toBe(80);

        $loserLotIds = RewardPointLot::query()->where('recipient_id', $loserId)->pluck('id')->all();

        expect(RewardPointMovement::query()->whereIn('lot_id', $loserLotIds)->where('kind', 'reserve')->count())->toBe(0);

        // Conflict recovery: the loser retries with a fresh key and succeeds.
        $recovery = ReserveRewardPointsAction::run('user', $loserId, $family, 'pts', 50, 'rsv:race-mismatch-retry', 'hold', CarbonImmutable::parse('2026-06-06T00:00:00Z'));

        expect($recovery)->not->toBe([])
            ->and(RewardPointLedger::balancesFor('user', $loserId, $family, 'pts', CarbonImmutable::parse('2026-06-07T00:00:00Z'))->reserved)->toBe(50);
    } finally {
        RewardLedgerWorkerDb::tearDown($driver, $database, $previous);
    }
})->with(['pgsql', 'mariadb']);

it('refuses concurrent mismatched debit reuse across disjoint recipients', function (string $driver) {
    [$database, $previous] = RewardLedgerWorkerDb::setUp($driver, 'debmismatch');

    try {
        $first = (string) Str::uuid();
        $second = (string) Str::uuid();
        $family = 'race-dmm-'.$driver;

        foreach ([$first, $second] as $index => $recipient) {
            CreditRewardPointsAction::run('user', $recipient, $family, 'pts', 40, CarbonImmutable::parse('2026-06-01T00:00:00Z'), CarbonImmutable::parse('2026-07-10T00:00:00Z'), null, null, 'op:race-dmm-credit-'.$index.'a', 'system', null, 'race-credit', CarbonImmutable::parse('2026-06-01T00:00:00Z'));
            CreditRewardPointsAction::run('user', $recipient, $family, 'pts', 40, CarbonImmutable::parse('2026-06-01T00:00:00Z'), CarbonImmutable::parse('2026-09-10T00:00:00Z'), null, null, 'op:race-dmm-credit-'.$index.'b', 'system', null, 'race-credit', CarbonImmutable::parse('2026-06-01T00:00:00Z'));
        }

        $payload = [
            'recipient_ids' => [$first, $second],
            'family' => $family,
            'unit' => 'pts',
            'points' => 50,
            'operation_key' => 'spend:race-mismatch-1',
            'occurred_at' => '2026-06-05T00:00:00Z',
        ];

        [$a, $b] = runLedgerRacePair($driver, $database, 'debit-mismatch', $payload, 'debmismatch');

        assertLedgerWorkersExited($a, $b);

        $wins = array_filter([$a, $b], static fn (array $r): bool => ($r['ok'] ?? false) === true);

        expect($wins)->toHaveCount(1);

        $winner = ($a['ok'] ?? false) === true ? $a : $b;
        $loser = ($a['ok'] ?? false) === true ? $b : $a;

        expect($loser['error'] ?? '')->toContain('different recipient');

        $operation = RewardPointOperation::query()->where('kind', 'debit')->where('operation_key', 'spend:race-mismatch-1')->firstOrFail();

        expect(RewardPointOperation::query()->where('kind', 'debit')->count())->toBe(1)
            ->and((string) $operation->recipient_id)->toBe($winner['recipient_id'] ?? null);

        $winnerId = (string) $winner['recipient_id'];
        $loserId = $winnerId === $first ? $second : $first;

        $winnerBuckets = RewardPointLedger::balancesFor('user', $winnerId, $family, 'pts', CarbonImmutable::parse('2026-06-06T00:00:00Z'));
        $loserBuckets = RewardPointLedger::balancesFor('user', $loserId, $family, 'pts', CarbonImmutable::parse('2026-06-06T00:00:00Z'));

        expect($winnerBuckets->spent)->toBe(50)
            ->and($winnerBuckets->available)->toBe(30)
            ->and($loserBuckets->spent)->toBe(0)
            ->and($loserBuckets->available)->toBe(80);

        $loserLotIds = RewardPointLot::query()->where('recipient_id', $loserId)->pluck('id')->all();

        expect(RewardPointMovement::query()->whereIn('lot_id', $loserLotIds)->where('kind', 'debit')->count())->toBe(0);

        $recovery = DebitRewardPointsAction::run('user', $loserId, $family, 'pts', 50, 'spend:race-mismatch-retry', 'spend', null, CarbonImmutable::parse('2026-06-06T00:00:00Z'));

        expect($recovery)->not->toBe([])
            ->and(RewardPointLedger::balancesFor('user', $loserId, $family, 'pts', CarbonImmutable::parse('2026-06-07T00:00:00Z'))->spent)->toBe(50);
    } finally {
        RewardLedgerWorkerDb::tearDown($driver, $database, $previous);
    }
})->with(['pgsql', 'mariadb']);

it('refuses concurrent refund reuse across different linked debits', function (string $driver) {
    [$database, $previous] = RewardLedgerWorkerDb::setUp($driver, 'refmismatch');

    try {
        $recipient = (string) Str::uuid();
        $family = 'race-fmm-'.$driver;

        CreditRewardPointsAction::run('user', $recipient, $family, 'pts', 30, CarbonImmutable::parse('2026-06-01T00:00:00Z'), null, null, null, 'op:race-fmm-credit-a', 'system', null, 'race-credit', CarbonImmutable::parse('2026-06-01T00:00:00Z'));
        CreditRewardPointsAction::run('user', $recipient, $family, 'pts', 30, CarbonImmutable::parse('2026-06-01T00:00:00Z'), null, null, null, 'op:race-fmm-credit-b', 'system', null, 'race-credit', CarbonImmutable::parse('2026-06-01T00:00:00Z'));
        DebitRewardPointsAction::run('user', $recipient, $family, 'pts', 20, 'spend:race-fmm-1', 'spend', null, CarbonImmutable::parse('2026-06-05T00:00:00Z'));
        DebitRewardPointsAction::run('user', $recipient, $family, 'pts', 20, 'spend:race-fmm-2', 'spend', null, CarbonImmutable::parse('2026-06-05T00:00:00Z'));

        $payload = [
            'linked_aggregates' => ['spend:race-fmm-1', 'spend:race-fmm-2'],
            'points' => 10,
            'refund_key' => 'refund:race-mismatch-1',
            'occurred_at' => '2026-06-06T00:00:00Z',
        ];

        [$a, $b] = runLedgerRacePair($driver, $database, 'refund-mismatch', $payload, 'refmismatch');

        assertLedgerWorkersExited($a, $b);

        $wins = array_filter([$a, $b], static fn (array $r): bool => ($r['ok'] ?? false) === true);

        expect($wins)->toHaveCount(1);

        $winner = ($a['ok'] ?? false) === true ? $a : $b;
        $loser = ($a['ok'] ?? false) === true ? $b : $a;

        expect($loser['error'] ?? '')->toContain('different debit aggregate');

        $operation = RewardPointOperation::query()->where('kind', 'refund')->where('operation_key', 'refund:race-mismatch-1')->firstOrFail();

        expect(RewardPointOperation::query()->where('kind', 'refund')->count())->toBe(1)
            ->and((string) $operation->linked_aggregate_key)->toBe($winner['linked'] ?? null)
            ->and((int) RewardPointMovement::query()->where('kind', 'refund')->sum('delta'))->toBe(10);

        $loserLinked = ($winner['linked'] ?? null) === 'spend:race-fmm-1' ? 'spend:race-fmm-2' : 'spend:race-fmm-1';

        $recovery = RefundRewardPointsAction::run('refund:race-mismatch-retry', $loserLinked, 10, 'retry', CarbonImmutable::parse('2026-06-07T00:00:00Z'));

        expect($recovery)->not->toBe([])
            ->and((int) RewardPointMovement::query()->where('kind', 'refund')->sum('delta'))->toBe(20);
    } finally {
        RewardLedgerWorkerDb::tearDown($driver, $database, $previous);
    }
})->with(['pgsql', 'mariadb']);

it('serializes concurrent double approvals without duplicate obligations', function (string $driver) {
    [$database, $previous] = RewardLedgerWorkerDb::setUp($driver, 'approve2');

    try {
        [$program, , $memberId] = raceSeedProgram('race-approve2', 'p1');

        $reader = new FakeRewardSourceFactReader;
        $reader->seed(RewardLedgerFixtures::fact('src-race-approve2-1', $memberId, '2026-06-12T00:00:00Z', RewardEligibleOutcome::EventCheckin, ['isAmbiguousDevice' => true]));

        $result = (new EvaluateRewardEntitlementAction($reader))->handle(
            $program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient,
            'src-race-approve2-1', CarbonImmutable::parse('2026-06-15T00:00:00Z'),
        );

        $staff = RewardLedgerFixtures::staffId();

        $payload = [
            'award_id' => (string) $result->award->getKey(),
            'staff_id' => $staff,
            'decided_at' => '2026-06-17T00:00:00Z',
            'fact' => raceFactPayload('src-race-approve2-1', $memberId),
        ];

        [$a, $b] = runLedgerRacePair($driver, $database, 'approve', $payload, 'approve2');

        assertLedgerWorkersExited($a, $b);

        Assert::assertTrue($a['ok'] ?? false, 'Approve worker A failed: '.($a['error'] ?? json_encode($a)));
        Assert::assertTrue($b['ok'] ?? false, 'Approve worker B failed: '.($b['error'] ?? json_encode($b)));

        expect(RewardAward::query()->firstOrFail()->status)->toBe(RewardAwardStatus::Approved)
            ->and(RewardAward::query()->firstOrFail()->entries()->count())->toBe(2)
            ->and(RewardSourceComponentClaim::query()->count())->toBe(1)
            ->and(RewardCapCounter::query()->where('scope', 'recipient_source_component')->firstOrFail()->consumed)->toBe(1);
    } finally {
        RewardLedgerWorkerDb::tearDown($driver, $database, $previous);
    }
})->with(['pgsql', 'mariadb']);

it('keeps direct approval and appeal overturn coherent under contention', function (string $driver) {
    [$database, $previous] = RewardLedgerWorkerDb::setUp($driver, 'approveover');

    try {
        [$program, , $memberId] = raceSeedProgram('race-approveover', 'p2');

        $reader = new FakeRewardSourceFactReader;
        $reader->seed(RewardLedgerFixtures::fact('src-race-ao-held', $memberId, '2026-06-12T00:00:00Z', RewardEligibleOutcome::EventCheckin, ['isAmbiguousDevice' => true]));
        $reader->seed(RewardLedgerFixtures::fact('src-race-ao-rej', $memberId, '2026-06-12T00:00:00Z', RewardEligibleOutcome::EventCheckin, ['verification' => RewardVerificationLevel::Unverified]));

        $held = (new EvaluateRewardEntitlementAction($reader))->handle(
            $program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient,
            'src-race-ao-held', CarbonImmutable::parse('2026-06-15T00:00:00Z'),
        );
        $rejected = (new EvaluateRewardEntitlementAction($reader))->handle(
            $program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient,
            'src-race-ao-rej', CarbonImmutable::parse('2026-06-15T00:00:00Z'),
        );

        $opened = OpenRewardAppealAction::run($rejected->award, 'user', $memberId, 'member', 'please', null, CarbonImmutable::parse('2026-06-20T00:00:00Z'));
        $reviewer = RewardLedgerFixtures::staffId();

        AdvanceRewardAppealAction::run($opened, RewardAppealStatus::InReview, 'user', $reviewer, 'admin', 'taking', null, CarbonImmutable::parse('2026-06-21T00:00:00Z'));

        $staff = RewardLedgerFixtures::staffId();

        [$a, $b] = runLedgerRacePair($driver, $database, ['approve', 'overturn'], [
            'approve' => [
                'award_id' => (string) $held->award->getKey(),
                'staff_id' => $staff,
                'decided_at' => '2026-06-22T00:00:00Z',
                'fact' => raceFactPayload('src-race-ao-held', $memberId),
            ],
            'overturn' => [
                'appeal_id' => (string) $opened->getKey(),
                'reviewer_id' => $reviewer,
                'decided_at' => '2026-06-22T00:00:00Z',
                'fact' => raceFactPayload('src-race-ao-rej', $memberId),
            ],
        ], 'approveover');

        assertLedgerWorkersExited($a, $b);

        Assert::assertTrue($a['ok'] ?? false, 'Approve worker failed: '.($a['error'] ?? json_encode($a)));
        Assert::assertTrue($b['ok'] ?? false, 'Overturn worker failed: '.($b['error'] ?? json_encode($b)));

        expect(RewardAward::query()->whereKey($held->award->getKey())->firstOrFail()->status)->toBe(RewardAwardStatus::Approved)
            ->and(RewardAward::query()->whereKey($rejected->award->getKey())->firstOrFail()->status)->toBe(RewardAwardStatus::Approved)
            ->and(RewardSourceComponentClaim::query()->count())->toBe(2)
            ->and(RewardAward::query()->whereKey($rejected->award->getKey())->firstOrFail()->entries()->count())->toBe(2);
    } finally {
        RewardLedgerWorkerDb::tearDown($driver, $database, $previous);
    }
})->with(['pgsql', 'mariadb']);

it('keeps milestone fulfillment coherent when a correction races it', function (string $driver) {
    [$database, $previous] = RewardLedgerWorkerDb::setUp($driver, 'milefulfill');

    try {
        $program = CreateRewardProgramAction::run('race-milef-m1-'.Str::lower(Str::random(4)), 'Race program', RewardProgramMode::Points, 'platform', null, 'race-test');
        $rules = RewardLedgerFixtures::rules();
        $rules['milestones'] = [['key' => 'race-fulfill-two', 'target_count' => 2, 'window_days' => 30, 'reward' => ['type' => 'points', 'points' => 25, 'unit' => 'pts']]];
        $at = CarbonImmutable::parse('2026-06-01T00:00:00Z');
        $version = PublishRewardProgramVersionAction::run($program, $at, $rules, RewardLedgerFixtures::terms(), null, null, null, $at);
        TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Ready);
        TransitionRewardProgramStatusAction::run($program, RewardProgramStatus::Active);
        $recipient = (string) Str::uuid();
        EnrollRewardMemberAction::run($program, 'user', $recipient, $version, 'member-portal', CarbonImmutable::parse('2026-06-10T00:00:00Z'));
        $program = $program->refresh();

        $reader = new FakeRewardSourceFactReader;
        $sources = ['src-race-milef-a', 'src-race-milef-b', 'src-race-milef-c'];

        foreach ($sources as $source) {
            $reader->seed(RewardLedgerFixtures::fact($source, $recipient, '2026-06-10T00:00:00Z'));

            (new RecordRewardMilestoneContributionAction($reader))->handle(
                'user', $recipient, $program->family_key, 'race-fulfill-two', 2, 30,
                RewardComponent::Points, ['points' => 25, 'unit' => 'pts'],
                $source, 'user', $recipient, RewardBeneficiaryRole::Participant,
                RewardEligibleOutcome::EventCheckin, CarbonImmutable::parse('2026-06-10T00:00:00Z'),
                (string) $program->getKey(),
            );
        }

        $cycle = RewardMilestoneCycle::query()->firstOrFail();
        $award = RewardAward::query()->whereKey($cycle->award_id)->firstOrFail();
        $staff = RewardLedgerFixtures::staffId();
        $revoke = RewardMilestoneContribution::query()->where('source_business_key', 'src-race-milef-a')->firstOrFail();

        $facts = [];

        foreach ($sources as $source) {
            $fact = raceFactPayload($source, $recipient);
            $fact['occurred_at'] = '2026-06-10T00:00:00Z';
            $facts[] = $fact;
        }

        [$a, $b] = runLedgerRacePair($driver, $database, ['fulfill', 'invalidate'], [
            'fulfill' => [
                'award_id' => (string) $award->getKey(),
                'staff_id' => $staff,
                'decided_at' => '2026-06-18T00:00:00Z',
                'facts' => $facts,
            ],
            'invalidate' => [
                'contribution_id' => (string) $revoke->getKey(),
                'staff_id' => $staff,
                'revoked_at' => '2026-06-18T00:00:00Z',
            ],
        ], 'milefulfill');

        assertLedgerWorkersExited($a, $b);

        Assert::assertTrue($a['ok'] ?? false, 'Fulfill worker failed: '.($a['error'] ?? json_encode($a)));
        Assert::assertTrue($b['ok'] ?? false, 'Invalidate worker failed: '.($b['error'] ?? json_encode($b)));

        expect($award->refresh()->status)->toBe(RewardAwardStatus::Fulfilled)
            ->and($cycle->refresh()->current_count)->toBe(2)
            ->and($cycle->status->value)->toBe('achieved')
            ->and(RewardPointLot::query()->where('award_id', $award->getKey())->count())->toBe(1);
    } finally {
        RewardLedgerWorkerDb::tearDown($driver, $database, $previous);
    }
})->with(['pgsql', 'mariadb']);

it('keeps refund and credit coherent under sequence contention', function (string $driver) {
    [$database, $previous] = RewardLedgerWorkerDb::setUp($driver, 'refundcredit');

    try {
        $recipient = (string) Str::uuid();
        $family = 'race-rfc2-'.$driver;

        CreditRewardPointsAction::run('user', $recipient, $family, 'pts', 40, CarbonImmutable::parse('2026-06-01T00:00:00Z'), CarbonImmutable::parse('2026-07-10T00:00:00Z'), null, null, 'op:race-rfc2-a', 'system', null, 'race-credit', CarbonImmutable::parse('2026-06-01T00:00:00Z'));
        CreditRewardPointsAction::run('user', $recipient, $family, 'pts', 40, CarbonImmutable::parse('2026-06-01T00:00:00Z'), CarbonImmutable::parse('2026-09-10T00:00:00Z'), null, null, 'op:race-rfc2-b', 'system', null, 'race-credit', CarbonImmutable::parse('2026-06-01T00:00:00Z'));
        CreditRewardPointsAction::run('user', $recipient, $family, 'pts', 40, CarbonImmutable::parse('2026-06-01T00:00:00Z'), null, null, null, 'op:race-rfc2-c', 'system', null, 'race-credit', CarbonImmutable::parse('2026-06-01T00:00:00Z'));
        DebitRewardPointsAction::run('user', $recipient, $family, 'pts', 50, 'spend:race-rfc2-1', 'spend', null, CarbonImmutable::parse('2026-06-05T00:00:00Z'));

        [$a, $b] = runLedgerRacePair($driver, $database, ['refund', 'credit'], [
            'refund' => [
                'linked_aggregate' => 'spend:race-rfc2-1',
                'points' => 20,
                'refund_prefix' => 'refund:race-c2-1',
                'same_key' => true,
                'occurred_at' => '2026-06-06T00:00:00Z',
            ],
            'credit' => [
                'recipient_id' => $recipient,
                'family' => $family,
                'unit' => 'pts',
                'points' => 30,
                'available_at' => '2026-06-06T00:00:00Z',
                'operation_prefix' => 'op:race-c2-credit',
            ],
        ], 'refundcredit');

        assertLedgerWorkersExited($a, $b);

        Assert::assertTrue($a['ok'] ?? false, 'Refund worker failed: '.($a['error'] ?? json_encode($a)));
        Assert::assertTrue($b['ok'] ?? false, 'Credit worker failed: '.($b['error'] ?? json_encode($b)));

        $seqs = RewardPointLot::query()->pluck('seq')->all();

        expect($seqs)->toHaveCount(count(array_unique($seqs)));

        $buckets = RewardPointLedger::balancesFor('user', $recipient, $family, 'pts', CarbonImmutable::parse('2026-06-07T00:00:00Z'));

        expect($buckets->total())->toBe(170)
            ->and($buckets->spent)->toBe(50);
    } finally {
        RewardLedgerWorkerDb::tearDown($driver, $database, $previous);
    }
})->with(['pgsql', 'mariadb']);

it('keeps refund and debit coherent under cross-path contention', function (string $driver) {
    [$database, $previous] = RewardLedgerWorkerDb::setUp($driver, 'refunddebit');

    try {
        $recipient = (string) Str::uuid();
        $family = 'race-rfd-'.$driver;

        CreditRewardPointsAction::run('user', $recipient, $family, 'pts', 40, CarbonImmutable::parse('2026-06-01T00:00:00Z'), CarbonImmutable::parse('2026-07-10T00:00:00Z'), null, null, 'op:race-rfd-a', 'system', null, 'race-credit', CarbonImmutable::parse('2026-06-01T00:00:00Z'));
        CreditRewardPointsAction::run('user', $recipient, $family, 'pts', 40, CarbonImmutable::parse('2026-06-01T00:00:00Z'), CarbonImmutable::parse('2026-09-10T00:00:00Z'), null, null, 'op:race-rfd-b', 'system', null, 'race-credit', CarbonImmutable::parse('2026-06-01T00:00:00Z'));
        CreditRewardPointsAction::run('user', $recipient, $family, 'pts', 40, CarbonImmutable::parse('2026-06-01T00:00:00Z'), null, null, null, 'op:race-rfd-c', 'system', null, 'race-credit', CarbonImmutable::parse('2026-06-01T00:00:00Z'));
        DebitRewardPointsAction::run('user', $recipient, $family, 'pts', 50, 'spend:race-rfd-1', 'spend', null, CarbonImmutable::parse('2026-06-05T00:00:00Z'));

        [$a, $b] = runLedgerRacePair($driver, $database, ['refund', 'debit'], [
            'refund' => [
                'linked_aggregate' => 'spend:race-rfd-1',
                'points' => 20,
                'refund_prefix' => 'refund:race-c3-1',
                'same_key' => true,
                'occurred_at' => '2026-06-06T00:00:00Z',
            ],
            'debit' => [
                'recipient_id' => $recipient,
                'family' => $family,
                'unit' => 'pts',
                'points' => 30,
                'operation_prefix' => 'spend:race-c3-2',
                'occurred_at' => '2026-06-06T00:00:00Z',
            ],
        ], 'refunddebit');

        assertLedgerWorkersExited($a, $b);

        Assert::assertTrue($a['ok'] ?? false, 'Refund worker failed: '.($a['error'] ?? json_encode($a)));
        Assert::assertTrue($b['ok'] ?? false, 'Debit worker failed: '.($b['error'] ?? json_encode($b)));

        $buckets = RewardPointLedger::balancesFor('user', $recipient, $family, 'pts', CarbonImmutable::parse('2026-06-07T00:00:00Z'));

        expect($buckets->total())->toBe(140)
            ->and($buckets->spent)->toBe(80)
            ->and($buckets->available)->toBe(60);
    } finally {
        RewardLedgerWorkerDb::tearDown($driver, $database, $previous);
    }
})->with(['pgsql', 'mariadb']);

it('keeps refund and expiry coherent under cross-path contention', function (string $driver) {
    [$database, $previous] = RewardLedgerWorkerDb::setUp($driver, 'refundexp');

    try {
        $recipient = (string) Str::uuid();
        $family = 'race-rfx-'.$driver;

        CreditRewardPointsAction::run('user', $recipient, $family, 'pts', 40, CarbonImmutable::parse('2026-06-01T00:00:00Z'), CarbonImmutable::parse('2026-07-01T00:00:00Z'), null, null, 'op:race-rfx-a', 'system', null, 'race-credit', CarbonImmutable::parse('2026-06-01T00:00:00Z'));
        CreditRewardPointsAction::run('user', $recipient, $family, 'pts', 40, CarbonImmutable::parse('2026-06-01T00:00:00Z'), CarbonImmutable::parse('2026-07-01T00:00:00Z'), null, null, 'op:race-rfx-b', 'system', null, 'race-credit', CarbonImmutable::parse('2026-06-01T00:00:00Z'));
        CreditRewardPointsAction::run('user', $recipient, $family, 'pts', 40, CarbonImmutable::parse('2026-06-01T00:00:00Z'), null, null, null, 'op:race-rfx-c', 'system', null, 'race-credit', CarbonImmutable::parse('2026-06-01T00:00:00Z'));
        DebitRewardPointsAction::run('user', $recipient, $family, 'pts', 50, 'spend:race-rfx-1', 'spend', null, CarbonImmutable::parse('2026-06-05T00:00:00Z'));

        [$a, $b] = runLedgerRacePair($driver, $database, ['refund', 'expire'], [
            'refund' => [
                'linked_aggregate' => 'spend:race-rfx-1',
                'points' => 20,
                'refund_prefix' => 'refund:race-c4-1',
                'same_key' => true,
                'occurred_at' => '2026-07-02T00:00:00Z',
            ],
            'expire' => [
                'recipient_id' => $recipient,
                'family' => $family,
                'unit' => 'pts',
                'as_of' => '2026-07-02T00:00:00Z',
            ],
        ], 'refundexp');

        assertLedgerWorkersExited($a, $b);

        Assert::assertTrue($a['ok'] ?? false, 'Refund worker failed: '.($a['error'] ?? json_encode($a)));
        Assert::assertTrue($b['ok'] ?? false, 'Expire worker failed: '.($b['error'] ?? json_encode($b)));

        $buckets = RewardPointLedger::balancesFor('user', $recipient, $family, 'pts', CarbonImmutable::parse('2026-07-03T00:00:00Z'));

        expect($buckets->total())->toBe(140)
            ->and($buckets->spent)->toBe(50)
            ->and($buckets->expired)->toBe(50)
            ->and($buckets->available)->toBe(40);
    } finally {
        RewardLedgerWorkerDb::tearDown($driver, $database, $previous);
    }
})->with(['pgsql', 'mariadb']);

it('keeps refund and reserve coherent under cross-path contention', function (string $driver) {
    [$database, $previous] = RewardLedgerWorkerDb::setUp($driver, 'refundrsv');

    try {
        $recipient = (string) Str::uuid();
        $family = 'race-rrf-'.$driver;

        CreditRewardPointsAction::run('user', $recipient, $family, 'pts', 100, CarbonImmutable::parse('2026-06-01T00:00:00Z'), null, null, null, 'op:race-rrf-credit', 'system', null, 'race-credit', CarbonImmutable::parse('2026-06-01T00:00:00Z'));
        DebitRewardPointsAction::run('user', $recipient, $family, 'pts', 20, 'spend:race-rrf-1', 'spend', null, CarbonImmutable::parse('2026-06-05T00:00:00Z'));

        [$a, $b] = runLedgerRacePair($driver, $database, ['refund', 'reserve'], [
            'linked_aggregate' => 'spend:race-rrf-1',
            'points' => 10,
            'refund_prefix' => 'refund:race-x-1',
            'same_key' => true,
            'occurred_at' => '2026-06-06T00:00:00Z',
            'recipient_id' => $recipient,
            'family' => $family,
            'unit' => 'pts',
            'reservation_prefix' => 'rsv:race-x-1',
        ], 'refundrsv');

        assertLedgerWorkersExited($a, $b);

        Assert::assertTrue($a['ok'] ?? false, 'Refund worker failed: '.($a['error'] ?? json_encode($a)));
        Assert::assertTrue($b['ok'] ?? false, 'Reserve worker failed: '.($b['error'] ?? json_encode($b)));

        $buckets = RewardPointLedger::balancesFor('user', $recipient, $family, 'pts', CarbonImmutable::parse('2026-06-07T00:00:00Z'));
        $total = $buckets->available + $buckets->reserved + $buckets->spent + $buckets->expired + $buckets->pending;

        expect($total)->toBe(110)
            ->and($buckets->spent)->toBe(20)
            ->and($buckets->reserved)->toBe(10);
    } finally {
        RewardLedgerWorkerDb::tearDown($driver, $database, $previous);
    }
})->with(['pgsql', 'mariadb']);

it('converges attested reversal and release with no resurrection', function (string $driver) {
    [$database, $previous] = RewardLedgerWorkerDb::setUp($driver, 'corrrevrel');

    try {
        [$program, , $memberId] = raceSeedProgram('race-crevrel', 'r1');

        $reader = new FakeRewardSourceFactReader;
        $reader->seed(RewardLedgerFixtures::fact('src-race-crevrel-1', $memberId));

        $result = (new EvaluateRewardEntitlementAction($reader))->handle(
            $program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient,
            'src-race-crevrel-1', CarbonImmutable::parse('2026-06-15T00:00:00Z'),
        );

        $staff = RewardLedgerFixtures::staffId();

        (new TransitionRewardAwardAction(null, $reader))->handle($result->award, RewardAwardStatus::Fulfilled, 'user', $staff, 'staff', 'fulfill', null, null, CarbonImmutable::parse('2026-06-16T00:00:00Z'));

        ReserveRewardPointsAction::run('user', $memberId, $program->family_key, 'pts', 10, 'rsv:race-crevrel-1', 'hold', CarbonImmutable::parse('2026-06-16T12:00:00Z'));

        [$a, $b] = runLedgerRacePair($driver, $database, ['reverse', 'release'], [
            'reverse' => [
                'award_id' => (string) $result->award->getKey(),
                'staff_id' => $staff,
                'decided_at' => '2026-06-17T00:00:00Z',
                'voidable' => ['rsv:race-crevrel-1'],
            ],
            'release' => [
                'reservation_key' => 'rsv:race-crevrel-1',
                'reason' => 'race-release',
                'occurred_at' => '2026-06-17T00:00:00Z',
            ],
        ], 'corrrevrel');

        assertLedgerWorkersExited($a, $b);

        Assert::assertTrue($a['ok'] ?? false, 'Reverse worker failed: '.($a['error'] ?? json_encode($a)));
        Assert::assertTrue($b['ok'] ?? false, 'Release worker failed: '.($b['error'] ?? json_encode($b)));

        expect(RewardAward::query()->firstOrFail()->status)->toBe(RewardAwardStatus::Reversed)
            ->and(RewardAdjustment::query()->count())->toBe(0)
            ->and(RewardPointMovement::query()->where('kind', 'reserve')->count())->toBe(1)
            ->and(RewardPointMovement::query()->where('kind', 'release')->count())->toBe(1)
            ->and((int) RewardPointMovement::query()->where('kind', 'adjust')->sum('delta'))->toBe(-10);

        $buckets = RewardPointLedger::balancesFor('user', $memberId, $program->family_key, 'pts', CarbonImmutable::parse('2026-06-18T00:00:00Z'));

        expect($buckets->available)->toBe(0)
            ->and($buckets->reserved)->toBe(0)
            ->and($buckets->spent)->toBe(0);
    } finally {
        RewardLedgerWorkerDb::tearDown($driver, $database, $previous);
    }
})->with(['pgsql', 'mariadb']);

it('blocks unattested reversal under release contention, then converges', function (string $driver) {
    [$database, $previous] = RewardLedgerWorkerDb::setUp($driver, 'corrrevun');

    try {
        [$program, , $memberId] = raceSeedProgram('race-crevun', 'r2');

        $reader = new FakeRewardSourceFactReader;
        $reader->seed(RewardLedgerFixtures::fact('src-race-crevun-1', $memberId));

        $result = (new EvaluateRewardEntitlementAction($reader))->handle(
            $program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient,
            'src-race-crevun-1', CarbonImmutable::parse('2026-06-15T00:00:00Z'),
        );

        $staff = RewardLedgerFixtures::staffId();

        (new TransitionRewardAwardAction(null, $reader))->handle($result->award, RewardAwardStatus::Fulfilled, 'user', $staff, 'staff', 'fulfill', null, null, CarbonImmutable::parse('2026-06-16T00:00:00Z'));

        ReserveRewardPointsAction::run('user', $memberId, $program->family_key, 'pts', 10, 'rsv:race-crevun-1', 'hold', CarbonImmutable::parse('2026-06-16T12:00:00Z'));

        [$a, $b] = runLedgerRacePair($driver, $database, ['reverse', 'release'], [
            'reverse' => [
                'award_id' => (string) $result->award->getKey(),
                'staff_id' => $staff,
                'decided_at' => '2026-06-17T00:00:00Z',
                'voidable' => [],
            ],
            'release' => [
                'reservation_key' => 'rsv:race-crevun-1',
                'reason' => 'race-release',
                'occurred_at' => '2026-06-17T00:00:00Z',
            ],
        ], 'corrrevun');

        assertLedgerWorkersExited($a, $b);

        Assert::assertTrue($b['ok'] ?? false, 'Release worker failed: '.($b['error'] ?? json_encode($b)));

        if (($a['ok'] ?? false) !== true) {
            expect($a['error'] ?? '')->toContain('unknown external status');
        }

        // Either the reversal won after the release, or it blocked and the
        // retry below converges; both paths end in one coherent settlement.
        $reversed = TransitionRewardAwardAction::run($result->award, RewardAwardStatus::Reversed, 'user', $staff, 'staff', 'race-retry', null, null, CarbonImmutable::parse('2026-06-17T12:00:00Z'));

        expect($reversed->status)->toBe(RewardAwardStatus::Reversed)
            ->and(RewardAdjustment::query()->count())->toBe(0)
            ->and(RewardPointMovement::query()->where('kind', 'release')->count())->toBe(1)
            ->and((int) RewardPointMovement::query()->where('kind', 'adjust')->sum('delta'))->toBe(-10);

        $buckets = RewardPointLedger::balancesFor('user', $memberId, $program->family_key, 'pts', CarbonImmutable::parse('2026-06-18T00:00:00Z'));

        expect($buckets->available)->toBe(0)
            ->and($buckets->reserved)->toBe(0);
    } finally {
        RewardLedgerWorkerDb::tearDown($driver, $database, $previous);
    }
})->with(['pgsql', 'mariadb']);

it('serializes reversal and capture to exactly one settlement', function (string $driver) {
    [$database, $previous] = RewardLedgerWorkerDb::setUp($driver, 'corrrevcap');

    try {
        [$program, , $memberId] = raceSeedProgram('race-crevcap', 'r3');

        $reader = new FakeRewardSourceFactReader;
        $reader->seed(RewardLedgerFixtures::fact('src-race-crevcap-1', $memberId));

        $result = (new EvaluateRewardEntitlementAction($reader))->handle(
            $program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient,
            'src-race-crevcap-1', CarbonImmutable::parse('2026-06-15T00:00:00Z'),
        );

        $staff = RewardLedgerFixtures::staffId();

        (new TransitionRewardAwardAction(null, $reader))->handle($result->award, RewardAwardStatus::Fulfilled, 'user', $staff, 'staff', 'fulfill', null, null, CarbonImmutable::parse('2026-06-16T00:00:00Z'));

        ReserveRewardPointsAction::run('user', $memberId, $program->family_key, 'pts', 10, 'rsv:race-crevcap-1', 'hold', CarbonImmutable::parse('2026-06-16T12:00:00Z'));

        [$a, $b] = runLedgerRacePair($driver, $database, ['reverse', 'capture'], [
            'reverse' => [
                'award_id' => (string) $result->award->getKey(),
                'staff_id' => $staff,
                'decided_at' => '2026-06-17T00:00:00Z',
                'voidable' => ['rsv:race-crevcap-1'],
            ],
            'capture' => [
                'recipient_id' => $memberId,
                'family' => $program->family_key,
                'unit' => 'pts',
                'points' => 10,
                'operation_key' => 'spend:race-crevcap-1',
                'reservation_key' => 'rsv:race-crevcap-1',
                'occurred_at' => '2026-06-17T00:00:00Z',
            ],
        ], 'corrrevcap');

        assertLedgerWorkersExited($a, $b);

        Assert::assertTrue($a['ok'] ?? false, 'Reverse worker failed: '.($a['error'] ?? json_encode($a)));

        expect(RewardAward::query()->firstOrFail()->status)->toBe(RewardAwardStatus::Reversed);

        if (($b['ok'] ?? false) === true) {
            $dispute = RewardAdjustment::query()->firstOrFail();

            expect($dispute->amount_snapshot['consumed_points'])->toBe(10)
                ->and((int) RewardPointMovement::query()->where('kind', 'debit')->sum('delta'))->toBe(10)
                ->and(RewardPointMovement::query()->where('kind', 'release')->count())->toBe(0);
        } else {
            expect($b['error'] ?? '')->toContain('already resolved')
                ->and(RewardAdjustment::query()->count())->toBe(0)
                ->and((int) RewardPointMovement::query()->where('kind', 'adjust')->sum('delta'))->toBe(-10);
        }

        $buckets = RewardPointLedger::balancesFor('user', $memberId, $program->family_key, 'pts', CarbonImmutable::parse('2026-06-18T00:00:00Z'));

        expect($buckets->available)->toBe(0)
            ->and($buckets->reserved)->toBe(0);
    } finally {
        RewardLedgerWorkerDb::tearDown($driver, $database, $previous);
    }
})->with(['pgsql', 'mariadb']);

it('serializes reversal and refund without resurrecting corrected credit', function (string $driver) {
    [$database, $previous] = RewardLedgerWorkerDb::setUp($driver, 'corrrevref');

    try {
        [$program, , $memberId] = raceSeedProgram('race-crevref', 'r4');

        $reader = new FakeRewardSourceFactReader;
        $reader->seed(RewardLedgerFixtures::fact('src-race-crevref-1', $memberId));

        $result = (new EvaluateRewardEntitlementAction($reader))->handle(
            $program, RewardComponent::Points, 'user', $memberId, RewardBeneficiaryRole::Recipient,
            'src-race-crevref-1', CarbonImmutable::parse('2026-06-15T00:00:00Z'),
        );

        $staff = RewardLedgerFixtures::staffId();

        (new TransitionRewardAwardAction(null, $reader))->handle($result->award, RewardAwardStatus::Fulfilled, 'user', $staff, 'staff', 'fulfill', null, null, CarbonImmutable::parse('2026-06-16T00:00:00Z'));

        DebitRewardPointsAction::run('user', $memberId, $program->family_key, 'pts', 6, 'spend:race-crevref-1', 'spend', null, CarbonImmutable::parse('2026-06-16T12:00:00Z'));

        [$a, $b] = runLedgerRacePair($driver, $database, ['reverse', 'refund'], [
            'reverse' => [
                'award_id' => (string) $result->award->getKey(),
                'staff_id' => $staff,
                'decided_at' => '2026-06-17T00:00:00Z',
                'voidable' => [],
            ],
            'refund' => [
                'linked_aggregate' => 'spend:race-crevref-1',
                'points' => 6,
                'refund_prefix' => 'refund:race-crevref-1',
                'same_key' => true,
                'occurred_at' => '2026-06-17T00:00:00Z',
            ],
        ], 'corrrevref');

        assertLedgerWorkersExited($a, $b);

        Assert::assertTrue($a['ok'] ?? false, 'Reverse worker failed: '.($a['error'] ?? json_encode($a)));

        expect(RewardAward::query()->firstOrFail()->status)->toBe(RewardAwardStatus::Reversed);

        if (($b['ok'] ?? false) === true) {
            expect(RewardAdjustment::query()->count())->toBe(0)
                ->and((int) RewardPointMovement::query()->where('kind', 'adjust')->sum('delta'))->toBe(-10);
        } else {
            expect($b['error'] ?? '')->toContain('reversed award');

            $dispute = RewardAdjustment::query()->firstOrFail();

            expect($dispute->amount_snapshot['consumed_points'])->toBe(6)
                ->and($dispute->amount_snapshot['clawed_points'])->toBe(4)
                ->and(RewardPointMovement::query()->where('kind', 'refund')->count())->toBe(0);
        }

        $buckets = RewardPointLedger::balancesFor('user', $memberId, $program->family_key, 'pts', CarbonImmutable::parse('2026-06-18T00:00:00Z'));

        expect($buckets->available)->toBe(0);
    } finally {
        RewardLedgerWorkerDb::tearDown($driver, $database, $previous);
    }
})->with(['pgsql', 'mariadb']);
