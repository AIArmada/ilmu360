<?php

declare(strict_types=1);

// Rw2a seams: the scalar MemberEnrollment Api, its feature preclaim binding,
// root enrollment delegation, the removed root preclaim action, and the
// ActivityRewardBusinessKey move into Shared vocabulary.

use AIArmada\Rewards\Enums\RewardProgramMode;
use App\Actions\Rewards\EnrollRewardProgramMemberAction;
use App\Features\Rewards\Actions\Enrollment\EvaluatePreclaimShareOutcomesAction;
use App\Features\Rewards\Api\MemberEnrollment;
use App\Models\User;
use App\Shared\ShareTracking\ActivityRewardBusinessKey;
use App\Shared\ShareTracking\ShareOutcomeType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Rewards\Host\Support\RewardCharacterizationFixtures;

uses(RefreshDatabase::class);

final class Rw2aFakeMemberEnrollment implements MemberEnrollment
{
    /** @var list<array{0: string, 1: string, 2: string, 3: string}> */
    public array $preclaims = [];

    public function preclaim(string $programId, string $memberId, string $recipientType, string $versionId): void
    {
        $this->preclaims[] = [$programId, $memberId, $recipientType, $versionId];
    }
}

it('binds the scalar member enrollment Api to the feature preclaim action', function (): void {
    expect(app(MemberEnrollment::class))->toBeInstanceOf(EvaluatePreclaimShareOutcomesAction::class);

    $api = new ReflectionClass(MemberEnrollment::class);
    $preclaim = $api->getMethod('preclaim');

    expect($api->isInterface())->toBeTrue()
        ->and($preclaim->getReturnType()?->getName())->toBe('void');

    foreach ($preclaim->getParameters() as $parameter) {
        expect($parameter->getType()?->getName())->toBe('string');
    }
});

it('routes root enrollment preclaim through the Api with scalar identities only', function (): void {
    [$program, $version] = RewardCharacterizationFixtures::activeProgram('rw2a-enrollment-seam', RewardProgramMode::Points);
    $user = User::factory()->create();
    $fake = new Rw2aFakeMemberEnrollment;
    $this->app->instance(MemberEnrollment::class, $fake);

    EnrollRewardProgramMemberAction::run($program, $user);

    expect($fake->preclaims)->toBe([[
        (string) $program->getKey(),
        (string) $user->getKey(),
        $user->getMorphClass(),
        (string) $version->getKey(),
    ]]);
});

it('keeps the root preclaim action deleted and the feature free of root types', function (): void {
    expect(is_file(app_path('Actions/Rewards/EvaluatePreclaimShareOutcomesAction.php')))->toBeFalse();

    $source = (string) file_get_contents(app_path('Features/Rewards/Actions/Enrollment/EvaluatePreclaimShareOutcomesAction.php'));

    expect($source)->not->toContain('App\\Models\\')
        ->and($source)->not->toContain('App\\Services\\')
        ->and($source)->not->toContain('App\\Support\\')
        ->and($source)->not->toContain('App\\Enums\\')
        ->and($source)->not->toContain('ShareSourceAttributionReceipt');
});

it('moves the activity reward business key into Shared vocabulary', function (): void {
    $transitionId = 'user:'.Str::uuid();

    expect(is_file(app_path('Support/ShareTracking/ActivityRewardBusinessKey.php')))->toBeFalse()
        ->and(ActivityRewardBusinessKey::for(ShareOutcomeType::Signup, $transitionId))
        ->toBe('activity:signup:'.$transitionId)
        ->and(ActivityRewardBusinessKey::parse('activity:signup:'.$transitionId)['source_transition_id'])
        ->toBe($transitionId);
});
