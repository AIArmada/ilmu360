<?php

declare(strict_types=1);

use AIArmada\FilamentRewards\Resources\RewardAwardResource\RewardAwardResource;
use AIArmada\FilamentRewards\Resources\RewardFraudResource\RewardFraudResource;
use AIArmada\FilamentRewards\Resources\RewardRecoveryResource\RewardRecoveryResource;
use App\Platform\Api\Resources\FilamentResourceDefinition;
use App\Platform\Api\Resources\ResourceDefinitionContext;
use App\Platform\Api\Resources\ResourceDefinitionRegistry;
use App\Platform\Api\Resources\ResourceSurface;
use Tests\TestCase;

pest()->extend(TestCase::class);

function forcedAdminResourceDefinition(string $resourceClass, string $key): FilamentResourceDefinition
{
    return new class(app(ResourceDefinitionContext::class), $resourceClass, $key) extends FilamentResourceDefinition
    {
        public function __construct(
            ResourceDefinitionContext $context,
            string $resourceClass,
            private readonly string $forcedKey,
        ) {
            parent::__construct($context, $resourceClass);
        }

        public function key(): string
        {
            return $this->forcedKey;
        }
    };
}

it('rejects an unexpected collision on a shared surface key', function (): void {
    expect(function (): void {
        $registry = new ResourceDefinitionRegistry;

        $registry->register(forcedAdminResourceDefinition(RewardAwardResource::class, 'unlisted-rewards'));
        $registry->register(forcedAdminResourceDefinition(RewardRecoveryResource::class, 'unlisted-rewards'));
    })->toThrow(
        RuntimeException::class,
        RewardAwardResource::class.' and '.RewardRecoveryResource::class,
    );
});

it('resolves the declared reward-awards collision to the first definition', function (): void {
    $context = app(ResourceDefinitionContext::class);
    $registry = new ResourceDefinitionRegistry;

    $registry->allowCollision(
        ResourceSurface::Admin,
        'reward-awards',
        RewardAwardResource::class,
        RewardFraudResource::class,
    );
    $awards = new FilamentResourceDefinition($context, RewardAwardResource::class);
    $fraud = new FilamentResourceDefinition($context, RewardFraudResource::class);

    $registry->register($awards);
    $registry->register($fraud);

    expect($awards->key())->toBe('reward-awards')
        ->and($fraud->key())->toBe('reward-awards')
        ->and($awards->resourceClass()::getModel())->toBe($fraud->resourceClass()::getModel())
        ->and($registry->resolve(ResourceSurface::Admin, 'reward-awards'))->toBe($awards)
        ->and($registry->reader(ResourceSurface::Admin, 'reward-awards'))->toBe($awards)
        ->and($registry->all(ResourceSurface::Admin))->toBe([$awards, $fraud]);
});

it('rejects a declared collision whose classes resolve different models', function (): void {
    $context = app(ResourceDefinitionContext::class);
    $registry = new ResourceDefinitionRegistry;

    $registry->allowCollision(
        ResourceSurface::Admin,
        'reward-awards',
        RewardAwardResource::class,
        RewardFraudResource::class,
    );

    $registry->register(new FilamentResourceDefinition($context, RewardAwardResource::class));

    expect(fn () => $registry->register(
        forcedAdminResourceDefinition(RewardRecoveryResource::class, 'reward-awards'),
    ))->toThrow(
        RuntimeException::class,
        'resolve different models',
    );
});

it('rejects a stale intentional collision when the discovery order changes', function (): void {
    $context = app(ResourceDefinitionContext::class);
    $registry = new ResourceDefinitionRegistry;

    $registry->allowCollision(
        ResourceSurface::Admin,
        'reward-awards',
        RewardAwardResource::class,
        RewardFraudResource::class,
    );

    $registry->register(new FilamentResourceDefinition($context, RewardFraudResource::class));

    expect(fn () => $registry->register(
        new FilamentResourceDefinition($context, RewardAwardResource::class),
    ))->toThrow(
        RuntimeException::class,
        'Stale intentional collision',
    );
});

it('rejects the reward-awards collision without the application declaration', function (): void {
    $context = app(ResourceDefinitionContext::class);
    $registry = new ResourceDefinitionRegistry;

    $registry->register(new FilamentResourceDefinition($context, RewardAwardResource::class));

    expect(fn () => $registry->register(
        new FilamentResourceDefinition($context, RewardFraudResource::class),
    ))->toThrow(
        RuntimeException::class,
        'Conflicting resource definitions for admin:reward-awards',
    );
});

it('requires a collision declaration before registering either resource', function (): void {
    $context = app(ResourceDefinitionContext::class);
    $registry = new ResourceDefinitionRegistry;

    $registry->register(new FilamentResourceDefinition($context, RewardAwardResource::class));

    expect(fn () => $registry->allowCollision(
        ResourceSurface::Admin,
        'reward-awards',
        RewardAwardResource::class,
        RewardFraudResource::class,
    ))->toThrow(RuntimeException::class, 'Declare resource collisions before registering admin:reward-awards');
});
