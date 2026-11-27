<?php

declare(strict_types=1);

// Rewards surface contracts: feature registration, package-canonical writer
// coverage, member/admin transport ownership, and absence of duplicate root
// paths or generic admin mutation access.

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
use AIArmada\Rewards\Models\RewardAppeal;
use AIArmada\Rewards\Models\RewardAward;
use AIArmada\Rewards\Models\RewardBenefitGrant;
use AIArmada\Rewards\Models\RewardBenefitItem;
use AIArmada\Rewards\Models\RewardFundingEntry;
use AIArmada\Rewards\Models\RewardPayee;
use AIArmada\Rewards\Models\RewardPayoutRequest;
use AIArmada\Rewards\Models\RewardProgram;
use AIArmada\Rewards\Models\RewardRecovery;
use AIArmada\Signals\Features\Ingestion\Models\SignalEvent;
use App\Features\Rewards\EntryPoints\Livewire\Pages\Dashboard\RewardMemberIndex;
use App\Features\Rewards\FeatureServiceProvider as RewardsFeatureServiceProvider;
use App\Features\Rewards\Policies\RewardAppealPolicy;
use App\Features\Rewards\Policies\RewardAwardPolicy;
use App\Features\Rewards\Policies\RewardBenefitGrantPolicy;
use App\Features\Rewards\Policies\RewardBenefitItemPolicy;
use App\Features\Rewards\Policies\RewardFundingEntryPolicy;
use App\Features\Rewards\Policies\RewardPayeePolicy;
use App\Features\Rewards\Policies\RewardPayoutRequestPolicy;
use App\Features\Rewards\Policies\RewardProgramPolicy;
use App\Features\Rewards\Policies\RewardRecoveryPolicy;
use App\Features\Rewards\Support\RewardMemberPresenter;
use App\Features\Rewards\Support\RewardPayoutMethods;
use App\Models\User;
use App\Platform\Api\Admin\AdminResourceMutationService;
use App\Platform\FeatureCatalog;
use App\Platform\FeatureModule;
use App\Platform\Mcp\Servers\AdminServer;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Feature\Rewards\Host\Support\RewardCharacterizationFixtures;

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

it('discovers the Rewards feature with the digest-slot claim and registers its provider once', function (): void {
    $catalog = FeatureCatalog::load(base_path());

    expect($catalog['features'])->toHaveKey('Rewards')
        ->and($catalog['owners']['table:reward_digest_slots'] ?? null)->toBe('Rewards');

    $manifest = json_decode(
        (string) file_get_contents(base_path('app/Features/Rewards/feature.json')), true, 512, JSON_THROW_ON_ERROR,
    );

    expect($manifest)->toBe([
        'schema' => 1,
        'name' => 'Rewards',
        'owns' => [
            'table:reward_digest_slots' => [
                App\Features\Rewards\Actions\GratitudeDigests\PlanRewardGratitudeDigestAction::class,
                App\Features\Rewards\Actions\GratitudeDigests\DispatchDueRewardDigestsAction::class,
                App\Features\Rewards\Actions\GratitudeDigests\CancelRewardDigestAction::class,
            ],
        ],
    ]);

    $providers = require base_path('bootstrap/providers.php');

    expect(array_keys($providers, App\Platform\PlatformServiceProvider::class, true))->toHaveCount(1)
        ->and(app()->getLoadedProviders())->toHaveKey(RewardsFeatureServiceProvider::class)
        ->and(is_subclass_of(RewardsFeatureServiceProvider::class, FeatureModule::class))->toBeTrue();

    $rewardPolicies = [
        RewardAppeal::class => RewardAppealPolicy::class,
        RewardAward::class => RewardAwardPolicy::class,
        RewardBenefitGrant::class => RewardBenefitGrantPolicy::class,
        RewardBenefitItem::class => RewardBenefitItemPolicy::class,
        RewardFundingEntry::class => RewardFundingEntryPolicy::class,
        RewardPayee::class => RewardPayeePolicy::class,
        RewardPayoutRequest::class => RewardPayoutRequestPolicy::class,
        RewardProgram::class => RewardProgramPolicy::class,
        RewardRecovery::class => RewardRecoveryPolicy::class,
    ];

    foreach ($rewardPolicies as $model => $policy) {
        expect(Gate::getPolicyFor($model))->toBeInstanceOf($policy)
            ->and(File::exists(app_path('Policies/'.class_basename($policy).'.php')))->toBeFalse();
    }
});

it('documents active integrations and package-canonical ownership', function (): void {
    $docblock = (string) new ReflectionClass(RewardsFeatureServiceProvider::class)->getDocComment();

    expect($docblock)->toContain('GratitudeDigest')
        ->and($docblock)->toContain('ShareGratitudeDigestMessages')
        ->and($docblock)->toContain('ManagedNotificationDelivery')
        ->and($docblock)->toContain('DigestRewardGratitudePlanner')
        ->and($docblock)->toContain('ResolveDigestMailAmbiguityCommand')
        ->and($docblock)->toContain('CompositeRewardSourceFactReader')
        ->and($docblock)->toContain('ActivityRewardSourceFactReader')
        ->and($docblock)->toContain('ShareAttributionRewardSourceFactReader')
        ->and($docblock)->toContain('ReferralRewardSourceFactReader')
        ->and($docblock)->toContain('Member presentation, HTTP endpoints, Livewire page')
        ->and($docblock)->toContain('VouchersRewardBenefitCouponFulfillment')
        ->and($docblock)->toContain('Communications after verifying the operator')
        ->and($docblock)->toContain('generic admin API/MCP exposes reward resources read-only')
        ->and($docblock)->toContain('Duplicate Communication rows remain possible')
        ->and($docblock)->toContain('package-canonical')
        ->and($docblock)->toContain('Active integration points');
});

it('keeps member reward presentation feature-local and the readers uncached', function (): void {
    $presenterPath = (string) new ReflectionClass(RewardMemberPresenter::class)->getFileName();
    $payoutMethodsPath = (string) new ReflectionClass(RewardPayoutMethods::class)->getFileName();

    expect($presenterPath)->toEndWith('/app/Features/Rewards/Support/RewardMemberPresenter.php')
        ->and($payoutMethodsPath)->toEndWith('/app/Features/Rewards/Support/RewardPayoutMethods.php')
        ->and(File::exists(app_path('Support/Rewards/RewardMemberPresenter.php')))->toBeFalse()
        ->and(File::exists(app_path('Support/Rewards/RewardPayoutMethods.php')))->toBeFalse()
        ->and(File::exists(app_path('Http/Controllers/Api/Frontend/RewardProgramController.php')))->toBeFalse()
        ->and(File::exists(app_path('Livewire/Pages/Dashboard/RewardMemberIndex.php')))->toBeFalse()
        ->and(File::exists(app_path('Support/Rewards/DigestRewardGratitudePlanner.php')))->toBeFalse()
        ->and(File::exists(app_path('Support/Rewards/PublishedProgramVersionsAdapter.php')))->toBeFalse()
        ->and(File::exists(app_path('Support/Rewards/PublishedRewardProgramVersionResolver.php')))->toBeFalse()
        ->and(File::exists(app_path('Support/Rewards/VouchersRewardBenefitCouponFulfillment.php')))->toBeFalse()
        ->and(File::exists(resource_path('views/livewire/pages/dashboard/reward-member-index.blade.php')))->toBeFalse()
        ->and(File::exists(app_path('Features/Rewards/Views/pages/dashboard/reward-member-index.blade.php')))->toBeTrue();

    foreach ([$presenterPath, $payoutMethodsPath] as $path) {
        $contents = (string) file_get_contents($path);

        expect(preg_match('/\b(?:DB::|Cache::|Redis::)|\bcache\s*\(|::(?:create|insert|upsert|updateOrCreate|firstOrCreate|forceCreate|destroy|truncate)\s*\(|->(?:save|update|delete|upsert|insert|increment|decrement)\s*\(/', $contents))
            ->toBe(0);
    }

    $memberReadSurfaces = [
        ...glob(app_path('Features/Rewards/EntryPoints/Http/Reward*Controller.php')) ?: [],
        app_path('Features/Rewards/EntryPoints/Livewire/Pages/Dashboard/RewardMemberIndex.php'),
        app_path('Features/Identity/EntryPoints/Livewire/Pages/Dashboard/UserDashboard.php'),
    ];

    foreach ($memberReadSurfaces as $path) {
        expect(File::exists($path))->toBeTrue();

        $contents = (string) file_get_contents($path);

        expect(preg_match('/\b(?:Cache::|cache\s*\(|remember\s*\()/i', $contents))
            ->toBe(0);
    }

    $manifest = json_decode(
        (string) file_get_contents(base_path('app/Features/Rewards/feature.json')), true, 512, JSON_THROW_ON_ERROR,
    );

    expect(array_keys($manifest['owns']))->toBe(['table:reward_digest_slots']);
});

it('records the enrollment signal on web first enrollment but not on API enrollment', function (): void {
    $webUser = User::factory()->create();
    [$webProgram] = RewardCharacterizationFixtures::activeProgram('rw0-signal-web');

    Livewire::actingAs($webUser)
        ->test(RewardMemberIndex::class)
        ->call('showTab', 'programs')
        ->call('enroll', (string) $webProgram->getKey())
        ->assertOk()
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

    // H17 recorded decision: the API passes recordSignal=false through the
    // feature Api, so API enrollment stays untracked while the first active
    // web enrollment records exactly one signal.
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

    $tools = app(App\Platform\Mcp\McpSurfaceRegistry::class)->adminTools();
    $instructions = new ReflectionClass(AdminServer::class)->getAttributes(Laravel\Mcp\Server\Attributes\Instructions::class)[0]->getArguments()[0];

    expect(array_values(array_filter($tools, static fn (string $tool): bool => str_contains($tool, 'Reward'))))->toBeEmpty()
        ->and($instructions)->toContain('Reward resources are read-only through the generic admin API and MCP')
        ->and($instructions)->toContain('must call an AIArmada\\Rewards package Action through the Rewards feature');

    $appRewardResources = [];

    $filamentPaths = array_filter(
        array_merge(
            glob(app_path('Features/*/EntryPoints/Filament'), GLOB_ONLYDIR) ?: [],
            glob(app_path('Platform/*/EntryPoints/Filament'), GLOB_ONLYDIR) ?: [],
            glob(app_path('Platform/*/Filament'), GLOB_ONLYDIR) ?: [],
        ),
        is_dir(...),
    );

    foreach ($filamentPaths as $filamentPath) {
        foreach (File::allFiles($filamentPath) as $file) {
            $name = $file->getFilename();

            if (str_starts_with($name, 'Reward') && str_ends_with($name, 'Resource.php')) {
                $appRewardResources[] = $name;
            }
        }
    }

    expect($appRewardResources)->toBeEmpty();
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

    expect($violations)->toBeEmpty();
});

it('writes reward_digest_slots only through the three feature digest actions', function (): void {
    $writers = [];

    foreach (rw0AppPhpSources() as $relative => $contents) {
        $ownsDigestSlot = str_contains($contents, 'RewardDigestSlot')
            || str_contains($contents, 'reward_digest_slot')
            || str_contains($contents, 'RewardsModel::digestSlotQuery');

        if (! $ownsDigestSlot) {
            continue;
        }

        if (preg_match('/(::query\(\)->create|::query\(\)->update|::query\(\)->delete|::create\(|::insert\(|::upsert\(|::destroy\(|::truncate\(|->forceFill\(|->save\(|->update\(|->delete\(|->firstOrCreate\(|->updateOrCreate\(|->increment\(|->decrement\(|::firstOrCreate\()/', $contents) === 1) {
            $writers[] = $relative;
        }
    }

    sort($writers);

    expect($writers)->toBe([
        'app/Features/Rewards/Actions/GratitudeDigests/CancelRewardDigestAction.php',
        'app/Features/Rewards/Actions/GratitudeDigests/DispatchDueRewardDigestsAction.php',
        'app/Features/Rewards/Actions/GratitudeDigests/PlanRewardGratitudeDigestAction.php',
    ]);
});
