<?php

declare(strict_types=1);

use App\Platform\FeatureCatalog;
use Tests\Fixtures\Architecture\FixtureProject;

/**
 * Explicitly configure the fixture and scan its manifests uncached.
 *
 * @param  array<string, array<string, string>>  $rootStoreWriters
 */
function scanWithRootStoreWriters(FixtureProject $project, array $rootStoreWriters): array
{
    $project->configure([], $rootStoreWriters);

    return FeatureCatalog::scan($project->path);
}

beforeEach(function (): void {
    $this->project = new FixtureProject;
});

afterEach(function (): void {
    $this->project->remove();
});

it('loads runtime feature metadata without architecture tooling configuration', function (): void {
    unlink($this->project->path.'/tools/architecture.json');

    $catalog = FeatureCatalog::load($this->project->path);

    expect($catalog['features'])->toBeEmpty()
        ->and($catalog['owners'])->toBeEmpty()
        ->and($catalog['rootStoreWriters'])->toBeEmpty();
});

it('allows several named actions to own mutations of the same resource', function (): void {
    $place = $this->project->writer('Orders', 'PlaceOrder');
    $cancel = $this->project->writer('Orders', 'CancelOrder');
    $export = $this->project->writer('Orders', 'ExportOrders', 'Persistence');
    $this->project->feature('Orders', [
        'table:orders' => [$place, $cancel],
        'table:order_lines' => [$place],
        'store:orders-export' => [$export],
    ]);

    $catalog = FeatureCatalog::load($this->project->path);
    expect($catalog)->toMatchArray(['owners' => [
        'table:orders' => 'Orders',
        'table:order_lines' => 'Orders',
        'store:orders-export' => 'Orders',
    ], 'writes' => [
        $place => ['table:orders', 'table:order_lines'],
        $cancel => ['table:orders'],
        $export => ['store:orders-export'],
    ]]);
});

it('rejects contributor writers because only Actions and Persistence can own writes', function (): void {
    $place = $this->project->writer('Orders', 'PlaceOrder');
    $lifecycle = $this->project->writer('Orders', 'OrderLifecycle', 'Contributors');
    $this->project->feature('Orders', [
        'table:orders' => [$place],
        'table:order_lifecycle' => [$lifecycle],
    ]);

    expect(fn (): array => FeatureCatalog::scan($this->project->path))
        ->toThrow(RuntimeException::class, 'write classes must be under');
});

it('registers exact root store writers with a declared adapter and expiry', function (): void {
    $writer = 'App\\Platform\\Cache\\RefreshRootCache';
    $this->project->write('app/Platform/Cache/RefreshRootCache.php', <<<'PHP'
<?php
namespace App\Platform\Cache;
class RefreshRootCache {}
PHP);
    $rootStoreWriters = [
        $writer => [
            'adapter' => 'cache',
            'resource' => 'store:infrastructure.root-cache',
            'reason' => 'This exact root adapter writes the infrastructure cache.',
            'expires' => '2999-12-31',
        ],
    ];

    $catalog = scanWithRootStoreWriters($this->project, $rootStoreWriters);

    expect($catalog['rootStoreWriters'])->toBe([
        $writer => [
            'adapter' => 'cache',
            'resource' => 'store:infrastructure.root-cache',
            'reason' => 'This exact root adapter writes the infrastructure cache.',
            'expires' => '2999-12-31',
        ],
    ])->and($catalog['owners'])->not->toHaveKey('store:infrastructure.root-cache');
});

it('rejects root store writers without exact class and store metadata', function (string $class, array $writer, ?string $file, string $message): void {
    if ($file !== null) {
        $classSeparator = strrpos($class, '\\');

        if ($classSeparator !== false) {
            $namespace = substr($class, 0, $classSeparator);
            $className = substr($class, $classSeparator + 1);
            $this->project->write($file, "<?php\nnamespace {$namespace};\nclass {$className} {}\n");
        }
    }

    $rootStoreWriters = [$class => $writer];

    expect(fn (): array => scanWithRootStoreWriters($this->project, $rootStoreWriters))
        ->toThrow(RuntimeException::class, $message);
})->with([
    'wildcard class' => [
        'App\\Platform\\Cache\\*',
        ['adapter' => 'cache', 'resource' => 'store:infrastructure.cache', 'reason' => 'A root cache writer.', 'expires' => '2999-12-31'],
        null,
        'exact fully-qualified class paths',
    ],
    'non-allowlisted namespace' => [
        'App\\Services\\CacheWriter',
        ['adapter' => 'cache', 'resource' => 'store:infrastructure.cache', 'reason' => 'A root cache writer.', 'expires' => '2999-12-31'],
        'app/Services/CacheWriter.php',
        'allowlisted non-feature namespace',
    ],
    'missing class file' => [
        'App\\Platform\\Cache\\MissingCacheWriter',
        ['adapter' => 'cache', 'resource' => 'store:infrastructure.cache', 'reason' => 'A root cache writer.', 'expires' => '2999-12-31'],
        null,
        'Missing/escaped root store writer',
    ],
    'unknown store' => [
        'App\\Platform\\Cache\\CacheWriter',
        ['adapter' => 'cache', 'resource' => 'store:unknown', 'reason' => 'A root cache writer.', 'expires' => '2999-12-31'],
        null,
        'named store resource',
    ],
    'invalid adapter family' => [
        'App\\Platform\\Cache\\CacheWriter',
        ['adapter' => 'database', 'resource' => 'store:infrastructure.cache', 'reason' => 'A root cache writer.', 'expires' => '2999-12-31'],
        null,
        'adapter family',
    ],
    'missing expiry field' => [
        'App\\Platform\\Cache\\CacheWriter',
        ['adapter' => 'cache', 'resource' => 'store:infrastructure.cache', 'reason' => 'A root cache writer.'],
        null,
        'must declare adapter, resource, reason and expires',
    ],
    'unexpected metadata field' => [
        'App\\Platform\\Cache\\CacheWriter',
        ['adapter' => 'cache', 'resource' => 'store:infrastructure.cache', 'reason' => 'A root cache writer.', 'expires' => '2999-12-31', 'model' => App\Models\User::class],
        null,
        'must declare adapter, resource, reason and expires',
    ],
    'missing reason' => [
        'App\\Platform\\Cache\\CacheWriter',
        ['adapter' => 'cache', 'resource' => 'store:infrastructure.cache', 'reason' => '', 'expires' => '2999-12-31'],
        null,
        'nonempty reason',
    ],
    'expired writer' => [
        'App\\Platform\\Cache\\CacheWriter',
        ['adapter' => 'cache', 'resource' => 'store:infrastructure.cache', 'reason' => 'A root cache writer.', 'expires' => '2000-01-01'],
        null,
        'Invalid/expired root store writer',
    ],
]);

it('rejects root store writers that collide with feature writer classes', function (): void {
    $writer = $this->project->writer('Orders', 'PlaceOrder');
    $this->project->feature('Orders', ['table:orders' => [$writer]]);
    $rootStoreWriters = [
        $writer => [
            'adapter' => 'cache',
            'resource' => 'store:infrastructure.orders-cache',
            'reason' => 'A root cache writer.',
            'expires' => '2999-12-31',
        ],
    ];

    expect(fn (): array => scanWithRootStoreWriters($this->project, $rootStoreWriters))
        ->toThrow(RuntimeException::class, 'Root store writer collides with feature writer');
});

it('rejects root store writers targeting feature-owned stores', function (): void {
    $featureWriter = $this->project->writer('Orders', 'RefreshOrderCache', 'Persistence');
    $rootWriter = 'App\\Platform\\Cache\\RefreshOrderCache';
    $this->project->feature('Orders', ['store:orders-cache' => [$featureWriter]]);
    $this->project->write('app/Platform/Cache/RefreshOrderCache.php', <<<'PHP'
<?php
namespace App\Platform\Cache;
class RefreshOrderCache {}
PHP);
    $rootStoreWriters = [
        $rootWriter => [
            'adapter' => 'cache',
            'resource' => 'store:orders-cache',
            'reason' => 'A root cache writer.',
            'expires' => '2999-12-31',
        ],
    ];

    expect(fn (): array => scanWithRootStoreWriters($this->project, $rootStoreWriters))
        ->toThrow(RuntimeException::class, 'Root store writer cannot target feature-owned resource');
});

it('maps morph aliases to resources owned by another feature', function (): void {
    $charge = $this->project->writer('Billing', 'ChargeOrder');
    $place = $this->project->writer('Orders', 'PlaceOrder');
    $this->project->feature('Billing', ['table:charges' => [$charge]], [
        'event' => 'table:orders',
    ]);
    $this->project->feature('Orders', ['table:orders' => [$place]]);

    $catalog = FeatureCatalog::load($this->project->path);

    expect($catalog['aliases']['Billing'])->toBe(['event' => 'table:orders']);
});

it('rejects two features claiming the same resource', function (): void {
    $charge = $this->project->writer('Billing', 'ChargeOrder');
    $place = $this->project->writer('Orders', 'PlaceOrder');
    $this->project->feature('Billing', ['table:orders' => [$charge]]);
    $this->project->feature('Orders', ['table:orders' => [$place]]);

    expect(fn (): array => FeatureCatalog::load($this->project->path))
        ->toThrow(RuntimeException::class, 'Duplicate owner: table:orders');
});

it('rejects a declared writer whose implementation is missing', function (): void {
    $this->project->feature('Orders', [
        'table:orders' => ['App\\Features\\Orders\\Actions\\PlaceOrder'],
    ]);

    expect(fn (): array => FeatureCatalog::load($this->project->path))
        ->toThrow(RuntimeException::class, 'Missing/escaped write class');
});

it('rejects a writer outside the owning action or persistence directories', function (string $feature, string $directory): void {
    $writer = $this->project->writer($feature, 'PlaceOrder', $directory);
    $this->project->feature($feature);
    $this->project->feature('Orders', ['table:orders' => [$writer]]);

    expect(fn (): array => FeatureCatalog::load($this->project->path))
        ->toThrow(RuntimeException::class, 'write classes must be under');
})->with([
    'HTTP entrypoint' => ['Orders', 'EntryPoints'],
    'another feature action' => ['Billing', 'Actions'],
    'another feature contributor' => ['Billing', 'Contributors'],
]);
