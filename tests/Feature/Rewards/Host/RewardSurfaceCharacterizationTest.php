<?php

declare(strict_types=1);

// Rw0 characterization: feature scaffold/registration, the API vs Livewire
// enrollment signal difference (H17), the generic admin API write refusal
// for every reward Filament resource (H19), and the static proof that app
// code performs no direct writes to package reward tables or to
// reward_digest_slots outside its three root digest actions.

use AIArmada\FilamentRewards\Resources\RewardAppealResource\RewardAppealResource;
use AIArmada\FilamentRewards\Resources\RewardAwardResource\RewardAwardResource;
use AIArmada\FilamentRewards\Resources\RewardBenefitGrantResource\RewardBenefitGrantResource;
use AIArmada\FilamentRewards\Resources\RewardBenefitItemResource\RewardBenefitItemResource;
use AIArmada\FilamentRewards\Resources\RewardFraudResource\RewardFraudResource;
use AIArmada\FilamentRewards\Resources\RewardFundingResource\RewardFundingResource;
use AIArmada\FilamentRewards\Resources\RewardPayeeResource\RewardPayeeResource;
use AIArmada\FilamentRewards\Resources\RewardPayoutResource\RewardPayoutResource;
use AIArmada\FilamentRewards\Resources\RewardProgramResource\RewardProgramResource;
use AIArmada\FilamentRewards\Resources\RewardRecoveryResource\RewardRecoveryResource;
use AIArmada\Signals\Models\SignalEvent;
use App\Features\Rewards\FeatureServiceProvider as RewardsFeatureServiceProvider;
use App\Livewire\Pages\Dashboard\RewardMemberIndex;
use App\Models\User;
use App\Platform\FeatureCatalog;
use App\Platform\FeatureModule;
use App\Support\Api\Admin\AdminResourceMutationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Feature\Rewards\Host\Support\RewardCharacterizationFixtures;

uses(RefreshDatabase::class);

/**
 * @return array<string, string> Relative app PHP path => contents.
 */
function rw0AppPhpSources(): array
{
    $root = base_path();
    $sources = [];

    foreach (File::allFiles(app_path()) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace($root.DIRECTORY_SEPARATOR, '', $file->getPathname());
        $sources[$relative] = (string) file_get_contents($file->getPathname());
    }

    ksort($sources);

    return $sources;
}

it('discovers the Rewards feature with no claims and registers its provider once', function (): void {
    $catalog = FeatureCatalog::load(base_path());

    expect($catalog['features'])->toHaveKey('Rewards')
        ->and($catalog['owners'])->not->toHaveKey('table:reward_digest_slots');

    $manifest = json_decode(
        (string) file_get_contents(base_path('app/Features/Rewards/feature.json')), true, 512, JSON_THROW_ON_ERROR,
    );

    expect($manifest)->toBe(['schema' => 2, 'name' => 'Rewards', 'owns' => []]);

    $providers = require base_path('bootstrap/providers.php');

    expect(array_keys($providers, RewardsFeatureServiceProvider::class, true))->toHaveCount(1)
        ->and(is_subclass_of(RewardsFeatureServiceProvider::class, FeatureModule::class))->toBeTrue();
});

it('documents the intentional root adapters and package-canonical holds', function (): void {
    $docblock = (string) (new ReflectionClass(RewardsFeatureServiceProvider::class))->getDocComment();

    expect($docblock)->toContain('DigestRewardGratitudePlanner')
        ->and($docblock)->toContain('ResolveDigestMailAmbiguityAction')
        ->and($docblock)->toContain('ResolveDigestMailAmbiguityCommand')
        ->and($docblock)->toContain('CompositeRewardSourceFactReader')
        ->and($docblock)->toContain('ActivityRewardSourceFactReader')
        ->and($docblock)->toContain('ShareAttributionRewardSourceFactReader')
        ->and($docblock)->toContain('ReferralRewardSourceFactReader')
        ->and($docblock)->toContain('RewardMemberPresenter')
        ->and($docblock)->toContain('RewardPayoutMethods')
        ->and($docblock)->toContain('VouchersRewardBenefitCouponFulfillment')
        ->and($docblock)->toContain('Reward*Policy')
        ->and($docblock)->toContain('ShareGratitudeDigestNotification')
        ->and($docblock)->toContain('DigestMailChannel')
        ->and($docblock)->toContain('InboxChannel')
        ->and($docblock)->toContain('GuardedDeliverySend')
        ->and($docblock)->toContain('Commands/schedules/transport')
        ->and($docblock)->toContain('package-canonical')
        ->and($docblock)->toContain('root adapters')
        ->and($docblock)->toContain('no Api');
});

it('records the enrollment signal on web first enrollment but not on API enrollment', function (): void {
    $webUser = User::factory()->create();
    [$webProgram] = RewardCharacterizationFixtures::activeProgram('rw0-signal-web');

    Livewire::actingAs($webUser)
        ->test(RewardMemberIndex::class)
        ->call('showTab', 'programs')
        ->call('enroll', (string) $webProgram->getKey())
        ->assertOk();

    $signals = SignalEvent::query()->where('event_name', 'rewards.program_enrolled');

    expect($signals->count())->toBe(1)
        ->and(data_get($signals->first()?->properties, 'surface'))->toBe('member-portal');

    $apiUser = User::factory()->create();
    [$apiProgram, $apiVersion] = RewardCharacterizationFixtures::activeProgram('rw0-signal-api');

    Sanctum::actingAs($apiUser);

    test()->postJson('/api/v1/rewards/programs/'.$apiProgram->getKey().'/enrollments', [
        'version_id' => (string) $apiVersion->getKey(),
    ])->assertCreated()->assertJsonPath('data.status', 'active');

    // Current asymmetry (H17): the API path calls the package enrollment
    // action directly, so it records no rewards.program_enrolled signal.
    expect($signals->count())->toBe(1);
});

it('denies generic admin API mutation for every reward Filament resource', function (): void {
    $resources = [
        RewardAppealResource::class,
        RewardAwardResource::class,
        RewardBenefitGrantResource::class,
        RewardBenefitItemResource::class,
        RewardFraudResource::class,
        RewardFundingResource::class,
        RewardPayeeResource::class,
        RewardPayoutResource::class,
        RewardProgramResource::class,
        RewardRecoveryResource::class,
    ];

    $mutations = app(AdminResourceMutationService::class);

    foreach ($resources as $resource) {
        expect($mutations->supports($resource))->toBeFalse();
    }

    $appRewardResources = [];

    foreach (File::allFiles(app_path('Filament')) as $file) {
        $name = $file->getFilename();

        if (str_starts_with($name, 'Reward') && str_ends_with($name, 'Resource.php')) {
            $appRewardResources[] = $name;
        }
    }

    expect($appRewardResources)->toBe([]);
});

it('has no app writes to package reward tables', function (): void {
    $packageModels = array_map(
        static fn (string $path): string => basename($path, '.php'),
        glob(base_path('vendor/aiarmada/rewards/src/Models/*.php')) ?: [],
    );

    expect($packageModels)->toHaveCount(30);

    $violations = [];

    foreach (rw0AppPhpSources() as $relative => $contents) {
        if (preg_match('/(?:DB::table|DB::connection\([^)]*\)->table)\(\s*[\'"]reward_/i', $contents) === 1) {
            $violations[] = $relative.': DB::table reward_*';
        }

        if (preg_match('/\b(?:insert\s+into|update|delete\s+from)\s+[`"\[]?reward_/i', $contents) === 1) {
            $violations[] = $relative.': raw SQL reward_*';
        }

        foreach ($packageModels as $model) {
            $quoted = preg_quote($model, '/');

            if (preg_match('/\b'.$quoted.'::(?:create|insert|upsert|updateOrCreate|firstOrCreate|forceCreate|destroy|truncate)\s*\(/', $contents) === 1) {
                $violations[] = $relative.': '.$model.' static write';
            }

            if (preg_match('/\b'.$quoted.'::(?:query\(\)|where\()(?:[^;]{0,800}?)->\s*(?:create|update|delete|save|upsert|insert|updateOrCreate|firstOrCreate|forceCreate|increment|decrement)\s*\(/s', $contents) === 1) {
                $violations[] = $relative.': '.$model.' query write';
            }
        }
    }

    expect($violations)->toBe([]);
});

it('writes reward_digest_slots only through the three root digest actions', function (): void {
    $writers = [];

    foreach (rw0AppPhpSources() as $relative => $contents) {
        if (! str_contains($contents, 'RewardDigestSlot')) {
            continue;
        }

        if (preg_match('/(::query\(\)->create|::query\(\)->update|::query\(\)->delete|::create\(|::insert\(|::upsert\(|::destroy\(|::truncate\(|->forceFill\(|->save\(|->update\(|->delete\(|->firstOrCreate\(|->updateOrCreate\(|->increment\(|->decrement\(|::firstOrCreate\()/', $contents) === 1) {
            $writers[] = $relative;
        }
    }

    sort($writers);

    expect($writers)->toBe([
        'app/Actions/Rewards/CancelRewardDigestAction.php',
        'app/Actions/Rewards/DispatchDueRewardDigestsAction.php',
        'app/Actions/Rewards/PlanRewardGratitudeDigestAction.php',
    ]);
});
