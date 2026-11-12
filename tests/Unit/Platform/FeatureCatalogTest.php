<?php

declare(strict_types=1);

use App\Platform\FeatureCatalog;
use Tests\Fixtures\Architecture\FixtureProject;

beforeEach(function (): void {
    $this->project = new FixtureProject;
});

afterEach(function (): void {
    $this->project->remove();
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

it('accepts contributor writers as named owners of their direct writes', function (): void {
    $place = $this->project->writer('Orders', 'PlaceOrder');
    $lifecycle = $this->project->writer('Orders', 'OrderLifecycle', 'Contributors');
    $this->project->feature('Orders', [
        'table:orders' => [$place],
        'table:order_lifecycle' => [$lifecycle],
    ]);

    $catalog = FeatureCatalog::load($this->project->path);

    expect($catalog['writes'][$lifecycle])->toBe(['table:order_lifecycle']);
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
