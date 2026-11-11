<?php

declare(strict_types=1);

use Tests\Fixtures\Architecture\FixtureProject;

beforeEach(function (): void {
    $this->project = new FixtureProject;
    $place = $this->project->writer('Orders', 'PlaceOrder');
    $this->project->feature('Orders', ['table:orders' => [$place]]);
    $this->project->feature('Billing');
    $this->project->write('app/Features/Orders/Models/Order.php', <<<'PHP'
<?php
namespace App\Features\Orders\Models;
class Order extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'orders';
}
PHP);
});

afterEach(function (): void {
    $this->project->remove();
});

it('rejects references to another feature private model', function (string $source): void {
    $this->project->write('app/Features/Billing/Actions/Charge.php', $source);

    $result = $this->project->check();
    expect($result)->toMatchArray(['exitCode' => 1, 'stderr' => '']);
    $findings = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR)['findings'];
    expect(array_column($findings, 'rule'))->toContain('feature-private')
        ->and(array_column($findings, 'target'))->toContain('App\\Features\\Orders\\Models\\Order');
})->with([
    'aliased import' => [<<<'PHP'
<?php
namespace App\Features\Billing\Actions;
use App\Features\Orders\Models\Order as Purchase;
class Charge
{
    public function handle(Purchase $order): void {}
}
PHP],
    'grouped import' => [<<<'PHP'
<?php
namespace App\Features\Billing\Actions;
use App\Features\Orders\Models\{Order};
class Charge
{
    public function handle(Order $order): void {}
}
PHP],
    'fully qualified type' => [<<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class Charge
{
    public function handle(\App\Features\Orders\Models\Order $order): void {}
}
PHP],
    'literal class name' => [<<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class Charge
{
    public const MODEL = 'App\\Features\\Orders\\Models\\Order';
}
PHP],
]);

it('allows public contracts and native feature provider registration', function (): void {
    $this->project->write('app/Features/Orders/Api/OrderSnapshot.php', <<<'PHP'
<?php
namespace App\Features\Orders\Api;
final readonly class OrderSnapshot
{
    public function __construct(public string $id) {}
}
PHP);
    $this->project->write('app/Features/Orders/Api/FindOrder.php', <<<'PHP'
<?php
namespace App\Features\Orders\Api;
interface FindOrder
{
    public function find(string $id): ?OrderSnapshot;
}
PHP);
    $this->project->write('app/Features/Billing/Actions/Charge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
use App\Features\Orders\Api\FindOrder;
class Charge
{
    public function __construct(private FindOrder $orders) {}
}
PHP);
    $this->project->write('bootstrap/providers.php', <<<'PHP'
<?php
use App\Features\Orders\FeatureServiceProvider;
return [FeatureServiceProvider::class, \App\Features\Billing\FeatureServiceProvider::class];
PHP);

    $result = $this->project->check();
    expect($result)->toMatchArray(['exitCode' => 0, 'stderr' => ''])
        ->and(json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR))->toBe([
            'schema' => 2,
            'findings' => [],
            'features' => ['Billing', 'Orders'],
        ]);
});

it('rejects an owned mutable model in a public boundary', function (): void {
    $this->project->write('app/Features/Orders/Api/FindOrder.php', <<<'PHP'
<?php
namespace App\Features\Orders\Api;
use App\Features\Orders\Models\Order;
interface FindOrder
{
    public function find(string $id): ?Order;
}
PHP);

    $result = $this->project->check();
    expect($result)->toMatchArray(['exitCode' => 1, 'stderr' => '']);
    $findings = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR)['findings'];
    expect(array_column($findings, 'rule'))->toContain('api-leak')
        ->and(array_column($findings, 'target'))->toContain('App\\Features\\Orders\\Models\\Order');
});

it('rejects an exception after its private dependency has been removed', function (): void {
    $this->project->writer('Billing', 'Charge');
    $this->project->configure([[
        'rule' => 'feature-private',
        'file' => 'app/Features/Billing/Actions/Charge.php',
        'target' => 'App\\Features\\Orders\\Models\\Order',
        'reason' => 'Temporary dependency during the billing conversion.',
        'owner' => 'Billing',
        'expires' => '2999-12-31',
    ]]);

    $result = $this->project->check();

    expect($result['exitCode'])->toBe(2)
        ->and($result['stderr'])->toContain('Remove stale exception');
});

it('accepts owned-write exceptions that only the PHPStan rule can consume', function (): void {
    $this->project->configure([
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Features/Billing/Actions/Charge.php',
            'target' => 'table:orders',
            'reason' => 'Documented root adapter consumed by the owned-write rule.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
        [
            'rule' => 'architecture.writeLocation',
            'file' => 'app/Features/Billing/Actions/Charge.php',
            'target' => 'table:orders',
            'reason' => 'Documented root adapter consumed by the owned-write rule.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
    ]);

    $result = $this->project->check();

    expect($result)->toMatchArray(['exitCode' => 0, 'stderr' => ''])
        ->and(json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR)['findings'])->toBe([]);
});

it('rejects an expired owned-write exception', function (): void {
    $this->project->configure([[
        'rule' => 'architecture.writeLocation',
        'file' => 'app/Features/Billing/Actions/Charge.php',
        'target' => 'table:orders',
        'reason' => 'Expired root adapter.',
        'owner' => 'Billing',
        'expires' => '2000-01-01',
    ]]);

    $result = $this->project->check();

    expect($result['exitCode'])->toBe(2)
        ->and($result['stderr'])->toContain('Invalid/expired exception');
});
