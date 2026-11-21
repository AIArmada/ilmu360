<?php

declare(strict_types=1);

use App\Platform\FeatureCatalog;
use JsonException;
use Tests\Fixtures\Architecture\FixtureProject;
use Tests\TestCase;

pest()->extend(TestCase::class);

it('loads compiled Platform manifests without re-reading changed source', function (): void {
    $project = new FixtureProject;

    try {
        $writer = $project->writer('Orders', 'PlaceOrder');
        $project->feature('Orders', ['table:orders' => [$writer]]);
        FeatureCatalog::flushRuntime();

        $catalog = FeatureCatalog::runtime($project->path);
        expect($catalog['owners']['table:orders'])->toBe('Orders');

        $project->write('bootstrap/cache/platform.php', "<?php\nreturn ".var_export($catalog, true).";\n");
        $project->write('app/Features/Orders/feature.json', '{invalid json');
        FeatureCatalog::flushRuntime();

        expect(FeatureCatalog::runtime($project->path)['owners']['table:orders'])->toBe('Orders')
            ->and(fn () => FeatureCatalog::load($project->path))->toThrow(JsonException::class);
    } finally {
        FeatureCatalog::flushRuntime();
        $project->remove();
    }
});

it('discovers every feature provider through Platform bootstrap', function (): void {
    $catalog = FeatureCatalog::runtime(base_path());
    $loaded = app()->getLoadedProviders();

    foreach (array_keys($catalog['features']) as $feature) {
        $provider = "App\\Features\\{$feature}\\FeatureServiceProvider";
        expect($loaded[$provider] ?? false)->toBeTrue();
    }
});
