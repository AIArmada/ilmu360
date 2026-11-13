<?php

declare(strict_types=1);

// Rw2a/Rw2b seams: the scalar MemberEnrollment Api, its feature enrollment
// binding, the published-version and account seams, root controller/page
// delegation, explicit-vs-published version resolution, the web-vs-API signal
// asymmetry, and the removed root enrollment action.

use AIArmada\Rewards\Actions\PublishRewardProgramVersionAction;
use AIArmada\Rewards\Enums\RewardProgramMode;
use AIArmada\Rewards\Models\RewardEnrollment;
use AIArmada\Rewards\Models\RewardEnrollmentAcceptance;
use AIArmada\Rewards\Models\RewardEnrollmentPeriod;
use AIArmada\Rewards\Models\RewardProgramVersion;
use AIArmada\Signals\Models\SignalEvent;
use App\Features\Rewards\Actions\Enrollment\EnrollRewardProgramMemberAction;
use App\Features\Rewards\Api\MemberEnrollment;
use App\Features\Rewards\Api\MemberEnrollmentSnapshot;
use App\Livewire\Pages\Dashboard\RewardMemberIndex;
use App\Models\User;
use App\Shared\Rewards\PublishedProgramVersions;
use App\Shared\ShareTracking\ActivityRewardBusinessKey;
use App\Shared\ShareTracking\ShareOutcomeType;
use App\Support\Rewards\PublishedProgramVersionsAdapter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Feature\Rewards\Host\Support\RewardCharacterizationFixtures;
use Tests\Feature\Rewards\Host\Support\RewardLedgerFixtures;

uses(RefreshDatabase::class);

final class Rw2bRecordingMemberEnrollment implements MemberEnrollment
{
    /** @var list<array{programId: string, memberId: string, recipientType: string, versionId: ?string, surface: string, recordSignal: bool, acceptedVia: ?string}> */
    public array $enrollments = [];

    /** @var list<array{programId: string, memberId: string, recipientType: string, reason: ?string}> */
    public array $leaves = [];

    /** @var list<array{programId: string, memberId: string, recipientType: string, versionId: ?string, acceptedVia: string}> */
    public array $reaccepts = [];

    public function __construct(private MemberEnrollment $inner) {}

    public function enroll(
        string $programId,
        string $memberId,
        string $recipientType,
        ?string $versionId = null,
        string $surface = 'member-portal',
        bool $recordSignal = true,
        ?string $acceptedVia = null,
    ): ?MemberEnrollmentSnapshot {
        $this->enrollments[] = compact('programId', 'memberId', 'recipientType', 'versionId', 'surface', 'recordSignal', 'acceptedVia');

        return $this->inner->enroll($programId, $memberId, $recipientType, $versionId, $surface, $recordSignal, $acceptedVia);
    }

    public function leave(
        string $programId,
        string $memberId,
        string $recipientType,
        ?string $reason = null,
    ): void {
        $this->leaves[] = compact('programId', 'memberId', 'recipientType', 'reason');

        $this->inner->leave($programId, $memberId, $recipientType, $reason);
    }

    public function reaccept(
        string $programId,
        string $memberId,
        string $recipientType,
        ?string $versionId = null,
        string $acceptedVia = 'member-portal',
    ): ?MemberEnrollmentSnapshot {
        $this->reaccepts[] = compact('programId', 'memberId', 'recipientType', 'versionId', 'acceptedVia');

        return $this->inner->reaccept($programId, $memberId, $recipientType, $versionId, $acceptedVia);
    }

    public function preclaim(
        string $programId,
        string $memberId,
        string $recipientType,
        string $versionId,
    ): void {
        $this->inner->preclaim($programId, $memberId, $recipientType, $versionId);
    }
}

it('binds the scalar member enrollment Api to the feature enrollment action', function (): void {
    expect(app(MemberEnrollment::class))->toBeInstanceOf(EnrollRewardProgramMemberAction::class)
        ->and(app(PublishedProgramVersions::class))->toBeInstanceOf(PublishedProgramVersionsAdapter::class);

    $api = new ReflectionClass(MemberEnrollment::class);

    expect($api->isInterface())->toBeTrue();

    foreach (['enroll', 'leave', 'reaccept', 'preclaim'] as $method) {
        expect($api->hasMethod($method))->toBeTrue();
    }

    foreach ($api->getMethods() as $method) {
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            $name = $type instanceof ReflectionNamedType ? $type->getName() : (string) $type;

            expect($name)->toBeIn(['string', 'bool'])
                ->and($name)->not->toContain('Illuminate')
                ->and($name)->not->toContain('Carbon')
                ->and($name)->not->toContain('App\\Models')
                ->and($name)->not->toContain('App\\Services')
                ->and($name)->not->toContain('App\\Support');
        }
    }

    $snapshot = new ReflectionClass(MemberEnrollmentSnapshot::class);

    expect($snapshot->isFinal())->toBeTrue()
        ->and($snapshot->isReadOnly())->toBeTrue();

    foreach ($snapshot->getConstructor()?->getParameters() ?? [] as $parameter) {
        $type = $parameter->getType();
        $name = $type instanceof ReflectionNamedType ? $type->getName() : (string) $type;

        expect($name)->toBe('string');
    }
});

it('returns a scalar enrollment snapshot from the real package write', function (): void {
    [$program] = RewardCharacterizationFixtures::activeProgram('rw2b-snapshot');
    $user = User::factory()->create();

    $snapshot = app(MemberEnrollment::class)->enroll(
        (string) $program->getKey(),
        (string) $user->getKey(),
        $user->getMorphClass(),
        null,
        'member-portal',
        true,
        'member-portal',
    );

    expect($snapshot)->toBeInstanceOf(MemberEnrollmentSnapshot::class)
        ->and($snapshot?->programId)->toBe((string) $program->getKey())
        ->and($snapshot?->memberId)->toBe((string) $user->getKey())
        ->and($snapshot?->status)->toBe('active')
        ->and($snapshot?->acceptanceId)->toBeNull()
        ->and(RewardEnrollment::query()->findOrFail($snapshot?->enrollmentId))->toBeInstanceOf(RewardEnrollment::class);
});

it('delegates the root api enrollment endpoints to the feature Api with scalar identities', function (): void {
    [$program, $version] = RewardCharacterizationFixtures::activeProgram('rw2b-controller');
    $user = User::factory()->create();
    $recording = new Rw2bRecordingMemberEnrollment(app(EnrollRewardProgramMemberAction::class));
    $this->app->instance(MemberEnrollment::class, $recording);

    Sanctum::actingAs($user);

    test()->postJson('/api/v1/rewards/programs/'.$program->getKey().'/enrollments', [
        'version_id' => (string) $version->getKey(),
    ])->assertCreated()->assertJsonPath('data.status', 'active');

    expect($recording->enrollments)->toBe([[
        'programId' => (string) $program->getKey(),
        'memberId' => (string) $user->getKey(),
        'recipientType' => $user->getMorphClass(),
        'versionId' => (string) $version->getKey(),
        'surface' => 'api',
        'recordSignal' => false,
        'acceptedVia' => 'api',
    ]]);

    $enrollmentId = (string) RewardEnrollment::query()
        ->where('program_id', $program->getKey())
        ->where('member_id', $user->getKey())
        ->value('id');

    $enrollmentAcceptance = RewardEnrollmentAcceptance::query()
        ->where('enrollment_id', $enrollmentId)
        ->orderByDesc('seq')
        ->firstOrFail();

    expect($enrollmentAcceptance->accepted_via)->toBe('api');

    test()->postJson('/api/v1/rewards/programs/'.$program->getKey().'/reaccept', [
        'version_id' => (string) $version->getKey(),
    ])->assertCreated()->assertJsonPath('data.enrollment_id', $enrollmentId);

    expect($recording->reaccepts)->toBe([[
        'programId' => (string) $program->getKey(),
        'memberId' => (string) $user->getKey(),
        'recipientType' => $user->getMorphClass(),
        'versionId' => (string) $version->getKey(),
        'acceptedVia' => 'api',
    ]]);

    test()->deleteJson('/api/v1/rewards/programs/'.$program->getKey().'/enrollments', [
        'reason' => 'api leave reason',
    ])->assertOk()->assertJsonPath('data.status', 'left');

    expect($recording->leaves)->toBe([[
        'programId' => (string) $program->getKey(),
        'memberId' => (string) $user->getKey(),
        'recipientType' => $user->getMorphClass(),
        'reason' => 'api leave reason',
    ]]);

    $period = RewardEnrollmentPeriod::query()
        ->where('enrollment_id', $enrollmentId)
        ->orderByDesc('seq')
        ->firstOrFail();

    expect($period->leave_reason)->toBe('api leave reason')
        ->and($period->left_at)->not->toBeNull();
});

it('keeps the reward policy gates inside the feature Api', function (): void {
    [$program, $version] = RewardCharacterizationFixtures::activeProgram('rw2b-gates');
    $user = User::factory()->create();

    Sanctum::actingAs($user);

    test()->deleteJson('/api/v1/rewards/programs/'.$program->getKey().'/enrollments')->assertForbidden();
    test()->postJson('/api/v1/rewards/programs/'.$program->getKey().'/reaccept', [
        'version_id' => (string) $version->getKey(),
    ])->assertForbidden();

    [$draft, $draftVersion] = RewardLedgerFixtures::seedVersionedProgram('rw2b-gates-draft', RewardProgramMode::Points);

    test()->postJson('/api/v1/rewards/programs/'.$draft->getKey().'/enrollments', [
        'version_id' => (string) $draftVersion->getKey(),
    ])->assertForbidden();
});

it('delegates the member page enrollment, reacceptance and leave to the feature Api', function (): void {
    [$program] = RewardCharacterizationFixtures::activeProgram('rw2b-page');
    $user = User::factory()->create();
    $recording = new Rw2bRecordingMemberEnrollment(app(EnrollRewardProgramMemberAction::class));
    $this->app->instance(MemberEnrollment::class, $recording);

    Livewire::actingAs($user)
        ->test(RewardMemberIndex::class)
        ->call('showTab', 'programs')
        ->call('enroll', (string) $program->getKey())
        ->assertSee('Enrolled')
        ->call('reaccept', (string) $program->getKey())
        ->assertSee('Terms reaccepted')
        ->set('leaveReason', 'taking a break')
        ->call('leave', (string) $program->getKey())
        ->assertSee('left the program');

    expect($recording->enrollments)->toBe([[
        'programId' => (string) $program->getKey(),
        'memberId' => (string) $user->getKey(),
        'recipientType' => $user->getMorphClass(),
        'versionId' => null,
        'surface' => 'member-portal',
        'recordSignal' => true,
        'acceptedVia' => 'member-portal',
    ]])->and($recording->reaccepts)->toBe([[
        'programId' => (string) $program->getKey(),
        'memberId' => (string) $user->getKey(),
        'recipientType' => $user->getMorphClass(),
        'versionId' => null,
        'acceptedVia' => 'member-portal',
    ]])->and($recording->leaves)->toBe([[
        'programId' => (string) $program->getKey(),
        'memberId' => (string) $user->getKey(),
        'recipientType' => $user->getMorphClass(),
        'reason' => 'taking a break',
    ]]);
});

it('keeps explicit api versions while web surfaces resolve the published version', function (): void {
    [$program, $firstVersion] = RewardCharacterizationFixtures::activeProgram('rw2b-version-resolution');
    $effective = CarbonImmutable::now('UTC')->addDay();

    $secondVersion = app(PublishRewardProgramVersionAction::class)->handle(
        $program->refresh(),
        $effective,
        RewardLedgerFixtures::rules(RewardProgramMode::Points),
        RewardLedgerFixtures::terms(RewardProgramMode::Points),
        'rw2b published version',
        null,
        null,
        CarbonImmutable::now('UTC'),
    );

    $this->travelTo($effective->addMinute());

    $webUser = User::factory()->create();
    $webSnapshot = app(MemberEnrollment::class)->enroll(
        (string) $program->getKey(),
        (string) $webUser->getKey(),
        $webUser->getMorphClass(),
        null,
        'member-portal',
        true,
        'member-portal',
    );

    $webAcceptance = RewardEnrollmentAcceptance::query()
        ->where('enrollment_id', $webSnapshot?->enrollmentId)
        ->firstOrFail();

    $apiUser = User::factory()->create();
    $apiSnapshot = app(MemberEnrollment::class)->enroll(
        (string) $program->getKey(),
        (string) $apiUser->getKey(),
        $apiUser->getMorphClass(),
        (string) $firstVersion->getKey(),
        'api',
        false,
        'api',
    );

    $apiAcceptance = RewardEnrollmentAcceptance::query()
        ->where('enrollment_id', $apiSnapshot?->enrollmentId)
        ->firstOrFail();

    expect((string) $webAcceptance->policy_version_id)->toBe((string) $secondVersion->getKey())
        ->and((string) $apiAcceptance->policy_version_id)->toBe((string) $firstVersion->getKey());
});

it('records the web enrollment signal once and never on api enrollment', function (): void {
    [$program] = RewardCharacterizationFixtures::activeProgram('rw2b-signal');
    $webUser = User::factory()->create();

    Livewire::actingAs($webUser)
        ->test(RewardMemberIndex::class)
        ->call('showTab', 'programs')
        ->call('enroll', (string) $program->getKey())
        ->assertOk()
        ->call('enroll', (string) $program->getKey())
        ->assertOk();

    $signals = SignalEvent::query()->where('event_name', 'rewards.program_enrolled');

    expect($signals->count())->toBe(1)
        ->and(data_get($signals->first()?->properties, 'surface'))->toBe('member-portal');

    $apiUser = User::factory()->create();
    $apiVersion = RewardProgramVersion::query()
        ->where('program_id', $program->getKey())
        ->orderByDesc('version')
        ->firstOrFail();

    Sanctum::actingAs($apiUser);

    test()->postJson('/api/v1/rewards/programs/'.$program->getKey().'/enrollments', [
        'version_id' => (string) $apiVersion->getKey(),
    ])->assertCreated();

    expect($signals->count())->toBe(1);
});

it('keeps the root enrollment action deleted and the feature free of root types', function (): void {
    expect(is_file(app_path('Actions/Rewards/EnrollRewardProgramMemberAction.php')))->toBeFalse();

    $featureFiles = File::files(app_path('Features/Rewards/Actions/Enrollment'));

    expect($featureFiles)->not->toBeEmpty();

    foreach ($featureFiles as $file) {
        $source = (string) file_get_contents($file->getPathname());

        expect($source)->not->toContain('App\\Models\\')
            ->and($source)->not->toContain('App\\Services\\')
            ->and($source)->not->toContain('App\\Support\\')
            ->and($source)->not->toContain('App\\Enums\\')
            ->and($source)->not->toContain('App\\Actions\\Rewards');
    }

    foreach (File::allFiles(app_path()) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        expect((string) file_get_contents($file->getPathname()))
            ->not->toContain('App\\Actions\\Rewards\\EnrollRewardProgramMemberAction');
    }
});

it('keeps the activity reward business key in Shared vocabulary', function (): void {
    $transitionId = 'user:'.Str::uuid();

    expect(is_file(app_path('Support/ShareTracking/ActivityRewardBusinessKey.php')))->toBeFalse()
        ->and(ActivityRewardBusinessKey::for(ShareOutcomeType::Signup, $transitionId))
        ->toBe('activity:signup:'.$transitionId)
        ->and(ActivityRewardBusinessKey::parse('activity:signup:'.$transitionId)['source_transition_id'])
        ->toBe($transitionId);
});
