<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Fixtures\Architecture\FixtureProject;

/** @return array{exitCode: int, stdout: string, stderr: string} */
function runOwnedWriteAnalysis(FixtureProject $project): array
{
    $root = dirname(__DIR__, 3);
    $paths = [$project->path.'/app'];
    foreach (['database', 'tests'] as $directory) {
        if (is_dir($project->path.'/'.$directory)) {
            $paths[] = $project->path.'/'.$directory;
        }
    }
    $paths = implode("\n        - ", $paths);

    $project->write('phpstan.neon', <<<NEON
parameters:
    customRulesetUsed: true
    paths:
        - {$paths}
    tmpDir: {$project->path}/cache
    bootstrapFiles:
        - {$root}/vendor/autoload.php
services:
    -
        class: Tools\Architecture\OwnedWriteRule
        arguments:
            projectRoot: {$project->path}
        tags:
            - phpstan.rules.rule
NEON);

    $process = new Process([
        PHP_BINARY,
        $root.'/vendor/phpstan/phpstan/phpstan',
        'analyse',
        '--configuration='.$project->path.'/phpstan.neon',
        '--no-progress',
        '--debug',
        '--error-format=json',
    ], $project->path, ['PAO_DISABLE' => '1']);
    $process->setTimeout(60);
    $process->run();

    return [
        'exitCode' => $process->getExitCode() ?? 1,
        'stdout' => $process->getOutput(),
        'stderr' => $process->getErrorOutput(),
    ];
}

/**
 * @return array{totals: array{errors: int, file_errors: int}, files: array<string, array{messages: list<array{identifier: string}>}>}
 */
function decodeOwnedWriteReport(string $stdout): array
{
    $start = strpos($stdout, '{');

    return json_decode(substr($stdout, $start === false ? 0 : $start), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * @param  array<string, array{adapter: 'cache'|'filesystem'|'redis', resource: string, reason: string, expires: string}>  $rootStoreWriters
 */
function configureRootStoreWriterDeclarations(FixtureProject $project, array $rootStoreWriters): void
{
    $project->write('tools/architecture.json', json_encode([
        'schema' => 1,
        'root_store_writers' => $rootStoreWriters,
        'exceptions' => [],
    ], JSON_THROW_ON_ERROR));
}

function ownedWriteFixture(): FixtureProject
{
    $project = new FixtureProject;
    $place = $project->writer('Orders', 'PlaceOrder');
    $project->feature('Orders', ['table:orders' => [$place]]);
    $project->feature('Billing');
    $project->write('app/Features/Orders/Models/Order.php', <<<'PHP'
<?php
namespace App\Features\Orders\Models;
class Order extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'orders';
}
PHP);

    return $project;
}

it('permits owning and canonical non-runtime mutations while rejecting unregistered and foreign application writes', function (): void {
    $project = new FixtureProject;

    try {
        $place = $project->writer('Orders', 'PlaceOrder');
        $cancel = $project->writer('Orders', 'CancelOrder');
        $charge = $project->writer('Billing', 'Charge');
        $project->feature('Orders', ['table:orders' => [$place, $cancel]]);
        $project->feature('Billing', ['table:charges' => [$charge]]);
        $project->write('app/Features/Orders/Models/Order.php', <<<'PHP'
<?php
namespace App\Features\Orders\Models;
class Order extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'orders';
}
PHP);
        $project->write('app/Features/Orders/EntryPoints/CacheAdapter.php', <<<'PHP'
<?php
namespace App\Features\Orders\EntryPoints;
use Illuminate\Contracts\Cache\Repository;
class CacheAdapter
{
    public function handle(Repository $cache): void { $cache->put('orders', 'changed'); }
}
PHP);
        $project->write('app/Features/Orders/EntryPoints/QueryAdapter.php', <<<'PHP'
<?php
namespace App\Features\Orders\EntryPoints;
use Illuminate\Database\Query\Builder;
class QueryAdapter
{
    public function handle(Builder $query): void { $query->update(['name' => 'Changed']); }
}
PHP);

        foreach (['PlaceOrder' => 'save', 'CancelOrder' => 'delete'] as $name => $method) {
            $project->write("app/Features/Orders/Actions/{$name}.php", <<<PHP
<?php
namespace App\Features\Orders\Actions;
use App\Features\Orders\Models\Order;
class {$name}
{
    public function handle(Order \$order): void { \$order->{$method}(); }
}
PHP);
        }

        $project->write('app/Features/Orders/EntryPoints/Unregistered.php', <<<'PHP'
<?php
namespace App\Features\Orders\EntryPoints;
use App\Features\Orders\Models\Order;
class Unregistered
{
    public function handle(Order $order): void { $order->update(['name' => 'Changed']); }
}
PHP);
        $project->write('app/Features/Orders/EntryPoints/InMemory.php', <<<'PHP'
<?php
namespace App\Features\Orders\EntryPoints;
use Illuminate\Support\Collection;
class InMemory
{
    public function handle(Collection $items): void { $items->put('key', 'value'); }
}
PHP);
        $project->write('app/Features/Billing/Actions/Charge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
use App\Features\Orders\Models\Order;
class Charge
{
    public function handle(Order $order): void { $order->save(); }
}
PHP);

        foreach ([
            'database/seeders/RootSeeder.php' => 'Database\\Seeders',
            'database/factories/RootFactory.php' => 'Database\\Factories',
            'database/migrations/RootMigration.php' => 'Database\\Migrations',
            'tests/Feature/FixtureTest.php' => 'Tests\\Feature',
            'app/Features/Billing/Database/Seeders/FeatureSeeder.php' => 'App\\Features\\Billing\\Database\\Seeders',
            'app/Features/Billing/Database/Factories/FeatureFactory.php' => 'App\\Features\\Billing\\Database\\Factories',
            'app/Features/Billing/Database/Migrations/FeatureMigration.php' => 'App\\Features\\Billing\\Database\\Migrations',
            'app/Services/SeederNamedWriter.php' => 'App\\Services',
            'app/Support/Database/Migrations/ProductionMigrationNamedWriter.php' => 'App\\Support\\Database\\Migrations',
        ] as $path => $namespace) {
            $name = pathinfo($path, PATHINFO_FILENAME);
            $project->write($path, <<<PHP
<?php
namespace {$namespace};
use App\Features\Orders\Models\Order;
class {$name}
{
    public function handle(Order \$order): void { \$order->save(); }
}
PHP);
        }

        $analysis = runOwnedWriteAnalysis($project);

        expect($analysis['exitCode'])->toBe(1, $analysis['stderr'].$analysis['stdout']);
        $report = decodeOwnedWriteReport($analysis['stdout']);
        expect($report['totals'])->toBe(['errors' => 0, 'file_errors' => 6]);
        $violations = [];
        foreach ($report['files'] as $path => $result) {
            $violations[basename($path)] = array_column($result['messages'], 'identifier');
        }
        ksort($violations);
        expect($violations)->toBe([
            'CacheAdapter.php' => ['architecture.writeLocation'],
            'Charge.php' => ['architecture.foreignWrite'],
            'ProductionMigrationNamedWriter.php' => ['architecture.foreignWrite'],
            'QueryAdapter.php' => ['architecture.writeLocation'],
            'SeederNamedWriter.php' => ['architecture.foreignWrite'],
            'Unregistered.php' => ['architecture.writeLocation'],
        ]);
    } finally {
        $project->remove();
    }
});

it('suppresses exact owned-write exceptions for foreign and unregistered writes', function (): void {
    $project = ownedWriteFixture();

    try {
        $project->write('app/Features/Billing/Actions/Charge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
use App\Features\Orders\Models\Order;
class Charge
{
    public function handle(Order $order): void { $order->save(); }
}
PHP);
        $project->write('app/Features/Orders/EntryPoints/Unregistered.php', <<<'PHP'
<?php
namespace App\Features\Orders\EntryPoints;
use App\Features\Orders\Models\Order;
class Unregistered
{
    public function handle(Order $order): void { $order->update(['name' => 'Changed']); }
}
PHP);
        $project->configure([
            [
                'rule' => 'architecture.foreignWrite',
                'file' => 'app/Features/Billing/Actions/Charge.php',
                'target' => 'table:orders',
                'model' => 'App\\Features\\Orders\\Models\\Order',
                'reason' => 'Documented root billing adapter.',
                'owner' => 'Billing',
                'expires' => '2999-12-31',
            ],
            [
                'rule' => 'architecture.writeLocation',
                'file' => 'app/Features/Orders/EntryPoints/Unregistered.php',
                'target' => 'table:orders',
                'model' => 'App\\Features\\Orders\\Models\\Order',
                'reason' => 'Documented root orders adapter.',
                'owner' => 'Orders',
                'expires' => '2999-12-31',
            ],
        ]);

        $analysis = runOwnedWriteAnalysis($project);

        expect($analysis['exitCode'])->toBe(0, $analysis['stderr'].$analysis['stdout'])
            ->and(decodeOwnedWriteReport($analysis['stdout'])['totals'])->toBe(['errors' => 0, 'file_errors' => 0]);
    } finally {
        $project->remove();
    }
});

it('applies a foreign-write exception to its exact model', function (): void {
    $project = ownedWriteFixture();

    try {
        $project->write('app/Features/Billing/Actions/Charge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
use App\Features\Orders\Models\Order;
class Charge
{
    public function handle(Order $order): void { $order->save(); }
}
PHP);
        $project->configure([[
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Features/Billing/Actions/Charge.php',
            'target' => 'table:orders',
            'model' => 'App\\Features\\Orders\\Models\\Order',
            'reason' => 'The exact foreign model is written by this bounded adapter.',
            'owner' => 'Orders',
            'expires' => '2999-12-31',
        ]]);

        $analysis = runOwnedWriteAnalysis($project);

        expect($analysis['exitCode'])->toBe(0, $analysis['stderr'].$analysis['stdout'])
            ->and(decodeOwnedWriteReport($analysis['stdout'])['totals'])
            ->toBe(['errors' => 0, 'file_errors' => 0]);
    } finally {
        $project->remove();
    }
});

it('resolves model tables returned through casted, plain, and elvis config fallbacks', function (): void {
    $project = ownedWriteFixture();

    try {
        $castedOrderWriter = $project->writer('Orders', 'PlaceCastedOrder');
        $elvisOrderWriter = $project->writer('Orders', 'PlaceElvisOrder');
        $coalesceOrderWriter = $project->writer('Orders', 'PlaceCoalesceOrder');
        $project->feature('Orders', [
            'table:orders' => ['App\\Features\\Orders\\Actions\\PlaceOrder'],
            'table:cast_orders' => [$castedOrderWriter],
            'table:elvis_orders' => [$elvisOrderWriter],
            'table:coalesce_orders' => [$coalesceOrderWriter],
        ]);
        $project->write('app/Features/Orders/Models/Order.php', <<<'PHP'
<?php
namespace App\Features\Orders\Models;
class Order extends \Illuminate\Database\Eloquent\Model
{
    public function getTable(): string
    {
        return config('orders.database.tables.orders', 'orders');
    }
}
PHP);
        $project->write('app/Features/Orders/Models/CastedOrder.php', <<<'PHP'
<?php
namespace App\Features\Orders\Models;
class CastedOrder extends \Illuminate\Database\Eloquent\Model
{
    public function getTable(): string
    {
        return (string) config('orders.database.tables.cast_orders', 'cast_orders');
    }
}
PHP);
        $project->write('app/Features/Orders/Models/ElvisOrder.php', <<<'PHP'
<?php
namespace App\Features\Orders\Models;
class ElvisOrder extends \Illuminate\Database\Eloquent\Model
{
    public function getTable(): string
    {
        return (string) (config('orders.database.tables.elvis_orders') ?: 'elvis_orders');
    }
}
PHP);
        $project->write('app/Features/Orders/Models/CoalesceOrder.php', <<<'PHP'
<?php
namespace App\Features\Orders\Models;
class CoalesceOrder extends \Illuminate\Database\Eloquent\Model
{
    protected $table = null;

    public function getTable(): string
    {
        return $this->table ?? config('orders.database.tables.coalesce_orders', 'coalesce_orders');
    }
}
PHP);
        $project->write('app/Features/Orders/Actions/PlaceOrder.php', <<<'PHP'
<?php
namespace App\Features\Orders\Actions;
use App\Features\Orders\Models\Order;
class PlaceOrder
{
    public function handle(Order $order): void
    {
        $order->save();
    }
}
PHP);
        $project->write('app/Features/Orders/Actions/PlaceCastedOrder.php', <<<'PHP'
<?php
namespace App\Features\Orders\Actions;
use App\Features\Orders\Models\CastedOrder;
class PlaceCastedOrder
{
    public function handle(CastedOrder $order): void
    {
        $order->save();
    }
}
PHP);
        $project->write('app/Features/Orders/Actions/PlaceElvisOrder.php', <<<'PHP'
<?php
namespace App\Features\Orders\Actions;
use App\Features\Orders\Models\ElvisOrder;
class PlaceElvisOrder
{
    public function handle(ElvisOrder $order): void
    {
        $order->save();
    }
}
PHP);
        $project->write('app/Features/Orders/Actions/PlaceCoalesceOrder.php', <<<'PHP'
<?php
namespace App\Features\Orders\Actions;
use App\Features\Orders\Models\CoalesceOrder;
class PlaceCoalesceOrder
{
    public function handle(CoalesceOrder $order): void
    {
        $order->save();
    }
}
PHP);

        $analysis = runOwnedWriteAnalysis($project);

        expect($analysis['exitCode'])->toBe(0, $analysis['stderr'].$analysis['stdout'])
            ->and(decodeOwnedWriteReport($analysis['stdout'])['totals'])
            ->toBe(['errors' => 0, 'file_errors' => 0]);
    } finally {
        $project->remove();
    }
});

it('resolves configured model table values before getTable fallbacks', function (): void {
    $project = new FixtureProject;

    try {
        $writer = $project->writer('Orders', 'SaveConfiguredOrder');
        $project->feature('Orders', ['table:configured_orders' => [$writer]]);
        $project->write('config/orders.php', <<<'PHP'
<?php
return [
    'database' => [
        'tables' => [
            'configured_orders' => env('ORDERS_TABLE', 'configured_orders'),
        ],
    ],
];
PHP);
        $project->write('app/Features/Orders/Models/ConfiguredOrder.php', <<<'PHP'
<?php
namespace App\Features\Orders\Models;
class ConfiguredOrder extends \Illuminate\Database\Eloquent\Model
{
    public function getTable(): string
    {
        return config('orders.database.tables.configured_orders', 'fallback_orders');
    }
}
PHP);
        $project->write('app/Features/Orders/Actions/SaveConfiguredOrder.php', <<<'PHP'
<?php
namespace App\Features\Orders\Actions;
use App\Features\Orders\Models\ConfiguredOrder;
class SaveConfiguredOrder
{
    public function handle(ConfiguredOrder $order): void
    {
        $order->save();
    }
}
PHP);

        $analysis = runOwnedWriteAnalysis($project);

        expect($analysis['exitCode'])->toBe(0, $analysis['stderr'].$analysis['stdout'])
            ->and(decodeOwnedWriteReport($analysis['stdout'])['totals'])
            ->toBe(['errors' => 0, 'file_errors' => 0]);
    } finally {
        $project->remove();
    }
});

it('resolves parent model deletes using the concrete Eloquent model table', function (): void {
    $project = new FixtureProject;

    try {
        $writer = $project->writer('Accounts', 'DeleteAccountUser');
        $project->feature('Accounts', ['table:account_users' => [$writer]]);
        $project->write('app/Features/Accounts/Concerns/DeletesAccountUser.php', <<<'PHP'
<?php
namespace App\Features\Accounts\Concerns;
trait DeletesAccountUser
{
    public function deleteFromParent(): void
    {
        parent::delete();
    }
}
PHP);
        $project->write('app/Features/Accounts/Models/AccountUser.php', <<<'PHP'
<?php
namespace App\Features\Accounts\Models;
use App\Features\Accounts\Concerns\DeletesAccountUser;
class AccountUser extends \Illuminate\Database\Eloquent\Model
{
    use DeletesAccountUser;

    protected $table = 'account_users';
}
PHP);

        $analysis = runOwnedWriteAnalysis($project);

        expect($analysis['exitCode'])->toBe(1, $analysis['stderr'].$analysis['stdout']);
        $report = decodeOwnedWriteReport($analysis['stdout']);
        $messages = [];

        foreach ($report['files'] as $fileResult) {
            array_push($messages, ...$fileResult['messages']);
        }

        expect($report['totals'])->toBe(['errors' => 0, 'file_errors' => 1])
            ->and($messages)->toHaveCount(1)
            ->and($messages[0]['identifier'])->toBe('architecture.writeLocation')
            ->and($messages[0]['message'])->toContain('table:account_users');
    } finally {
        $project->remove();
    }
});

it('matches a root parent delete exception to the exact application model', function (): void {
    $project = new FixtureProject;

    try {
        $writer = $project->writer('Accounts', 'DeleteAccountUser');
        $project->feature('Accounts', ['table:account_users' => [$writer]]);
        $project->write('app/Models/AccountUser.php', <<<'PHP'
<?php
namespace App\Models;
class AccountUser extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'account_users';

    public function delete(): ?bool
    {
        return parent::delete();
    }
}
PHP);
        $project->configure([[
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Models/AccountUser.php',
            'target' => 'table:account_users',
            'model' => 'App\\Models\\AccountUser',
            'reason' => 'The root account model preserves its package deletion lifecycle.',
            'owner' => 'Accounts',
            'expires' => '2999-12-31',
        ]]);

        $analysis = runOwnedWriteAnalysis($project);

        expect($analysis['exitCode'])->toBe(0, $analysis['stderr'].$analysis['stdout'])
            ->and(decodeOwnedWriteReport($analysis['stdout'])['totals'])
            ->toBe(['errors' => 0, 'file_errors' => 0]);
    } finally {
        $project->remove();
    }
});

it('permits store mutations declared for one feature-owned store', function (): void {
    $project = new FixtureProject;

    try {
        $cacheWriter = $project->writer('Orders', 'RefreshOrderCache', 'Persistence');
        $project->feature('Orders', ['store:orders-cache' => [$cacheWriter]]);
        $project->feature('Billing');
        $project->write('app/Features/Orders/Persistence/RefreshOrderCache.php', <<<'PHP'
<?php
namespace App\Features\Orders\Persistence;
use Illuminate\Contracts\Cache\Repository;
class RefreshOrderCache
{
    public function handle(Repository $cache): void
    {
        $cache->put('orders:key', []);
    }
}
PHP);

        $analysis = runOwnedWriteAnalysis($project);

        expect($analysis['exitCode'])->toBe(0, $analysis['stderr'].$analysis['stdout'])
            ->and(decodeOwnedWriteReport($analysis['stdout'])['totals'])
            ->toBe(['errors' => 0, 'file_errors' => 0]);
    } finally {
        $project->remove();
    }
});

it('resolves writes from a registered root store writer to its unowned store', function (): void {
    $project = new FixtureProject;

    try {
        $project->feature('Orders');
        $writer = 'App\\Platform\\Infrastructure\\WriteRootCache';
        $project->write('app/Platform/Infrastructure/WriteRootCache.php', <<<'PHP'
<?php
namespace App\Platform\Infrastructure;
use Illuminate\Support\Facades\Cache;
class WriteRootCache
{
    public function handle(): void
    {
        Cache::put('root:key', 'value');
    }
}
PHP);
        configureRootStoreWriterDeclarations($project, [
            $writer => [
                'adapter' => 'cache',
                'resource' => 'store:infrastructure.root-cache',
                'reason' => 'This exact root adapter caches infrastructure values.',
                'expires' => '2999-12-31',
            ],
        ]);

        $analysis = runOwnedWriteAnalysis($project);

        expect($analysis['exitCode'])->toBe(0, $analysis['stderr'].$analysis['stdout'])
            ->and(decodeOwnedWriteReport($analysis['stdout'])['totals'])
            ->toBe(['errors' => 0, 'file_errors' => 0]);
    } finally {
        $project->remove();
    }
});

it('rejects a root cache writer when the mutated receiver belongs to another adapter family', function (string $facade, string $method): void {
    $project = new FixtureProject;

    try {
        $project->feature('Orders');
        $writer = 'App\\Platform\\Infrastructure\\WriteRootCache';
        $project->write('app/Platform/Infrastructure/WriteRootCache.php', <<<PHP
<?php
namespace App\\Platform\\Infrastructure;
use Illuminate\\Support\\Facades\\{$facade};
class WriteRootCache
{
    public function handle(): void
    {
        {$facade}::{$method}('root:key', 'value');
    }
}
PHP);
        configureRootStoreWriterDeclarations($project, [
            $writer => [
                'adapter' => 'cache',
                'resource' => 'store:infrastructure.root-cache',
                'reason' => 'This exact root adapter caches infrastructure values.',
                'expires' => '2999-12-31',
            ],
        ]);

        $analysis = runOwnedWriteAnalysis($project);
        $report = decodeOwnedWriteReport($analysis['stdout']);
        $violations = [];

        foreach ($report['files'] as $path => $result) {
            $violations[basename($path)] = array_column($result['messages'], 'identifier');
        }
        ksort($violations);

        expect($analysis['exitCode'])->toBe(1, $analysis['stderr'].$analysis['stdout'])
            ->and($report['totals'])->toBe(['errors' => 0, 'file_errors' => 1])
            ->and($violations)->toBe(['WriteRootCache.php' => ['architecture.writeLocation']]);
    } finally {
        $project->remove();
    }
})->with([
    'filesystem receiver' => ['Storage', 'put'],
    'redis receiver' => ['Redis', 'set'],
]);

it('limits a resolved table exception to its exact package model', function (): void {
    $project = new FixtureProject;

    try {
        $project->feature('Orders');
        $project->feature('Billing');
        $project->write('app/External/PackageOrder.php', <<<'PHP'
<?php
namespace Fixture\Package;
class PackageOrder extends \Illuminate\Database\Eloquent\Model
{
    public function getTable(): string
    {
        return config('orders.database.tables.orders', 'orders');
    }
}
PHP);
        $project->write('app/External/PackageRefund.php', <<<'PHP'
<?php
namespace Fixture\Package;
class PackageRefund extends \Illuminate\Database\Eloquent\Model
{
    public function getTable(): string
    {
        return config('orders.database.tables.orders', 'orders');
    }
}
PHP);
        $project->write('app/Features/Billing/Actions/RecordOrder.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
use Fixture\Package\PackageOrder;
use Fixture\Package\PackageRefund;
class RecordOrder
{
    public function handle(PackageOrder $order, PackageRefund $refund): void
    {
        $order->save();
        $refund->save();
    }
}
PHP);
        $project->configure([[
            'rule' => 'architecture.writeLocation',
            'file' => 'app/Features/Billing/Actions/RecordOrder.php',
            'target' => 'table:orders',
            'model' => 'Fixture\\Package\\PackageOrder',
            'reason' => 'The exact package order model is written through this integration action.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ]]);

        $analysis = runOwnedWriteAnalysis($project);

        expect($analysis['exitCode'])->toBe(1, $analysis['stderr'].$analysis['stdout']);
        $report = decodeOwnedWriteReport($analysis['stdout']);
        expect($report['totals'])->toBe(['errors' => 0, 'file_errors' => 1])
            ->and(array_column($report['files'][$project->path.'/app/Features/Billing/Actions/RecordOrder.php']['messages'], 'identifier'))
            ->toBe(['architecture.writeLocation']);
    } finally {
        $project->remove();
    }
});

it('resolves configured and constant table names for query-builder writes', function (): void {
    $project = new FixtureProject;

    try {
        $writer = $project->writer('Orders', 'PurgeOrders');
        $project->feature('Orders', [
            'table:orders' => [$writer],
            'table:archived_orders' => [$writer],
        ]);
        $project->write('app/Features/Orders/Actions/PurgeOrders.php', <<<'PHP'
<?php
namespace App\Features\Orders\Actions;
use Illuminate\Support\Facades\DB;
class PurgeOrders
{
    private const TABLE = 'orders';
    private const TABLES = ['orders', 'archived_orders'];

    public function handle(): void
    {
        DB::table(config('orders.database.table', 'orders'))->delete();
        DB::table(self::TABLE)->insert([]);

        foreach (self::TABLES as $table) {
            DB::table($table)->delete();
        }
    }
}
PHP);

        $analysis = runOwnedWriteAnalysis($project);

        expect($analysis['exitCode'])->toBe(0, $analysis['stderr'].$analysis['stdout'])
            ->and(decodeOwnedWriteReport($analysis['stdout'])['totals'])
            ->toBe(['errors' => 0, 'file_errors' => 0]);
    } finally {
        $project->remove();
    }
});

it('does not suppress write exceptions for another file or target', function (): void {
    $project = ownedWriteFixture();

    try {
        $project->write('app/Features/Billing/Actions/Charge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
use App\Features\Orders\Models\Order;
class Charge
{
    public function handle(Order $order): void { $order->save(); }
}
PHP);
        $project->write('app/Features/Orders/EntryPoints/Unregistered.php', <<<'PHP'
<?php
namespace App\Features\Orders\EntryPoints;
use App\Features\Orders\Models\Order;
class Unregistered
{
    public function handle(Order $order): void { $order->update(['name' => 'Changed']); }
}
PHP);
        $project->configure([
            [
                'rule' => 'architecture.foreignWrite',
                'file' => 'app/Features/Billing/Actions/Other.php',
                'target' => 'table:orders',
                'model' => 'App\\Features\\Orders\\Models\\Order',
                'reason' => 'Wrong file.',
                'owner' => 'Billing',
                'expires' => '2999-12-31',
            ],
            [
                'rule' => 'architecture.writeLocation',
                'file' => 'app/Features/Orders/EntryPoints/Unregistered.php',
                'target' => 'table:charges',
                'model' => 'App\\Features\\Orders\\Models\\Order',
                'reason' => 'Wrong target.',
                'owner' => 'Orders',
                'expires' => '2999-12-31',
            ],
        ]);

        $analysis = runOwnedWriteAnalysis($project);

        expect($analysis['exitCode'])->toBe(1, $analysis['stderr'].$analysis['stdout']);
        $report = decodeOwnedWriteReport($analysis['stdout']);
        expect($report['totals'])->toBe(['errors' => 0, 'file_errors' => 2]);
        $violations = [];
        foreach ($report['files'] as $path => $result) {
            $violations[basename($path)] = array_column($result['messages'], 'identifier');
        }
        ksort($violations);
        expect($violations)->toBe([
            'Charge.php' => ['architecture.foreignWrite'],
            'Unregistered.php' => ['architecture.writeLocation'],
        ]);
    } finally {
        $project->remove();
    }
});

it('limits an unknown table exception to its exact model class', function (): void {
    $project = new FixtureProject;

    try {
        $charge = $project->writer('Billing', 'Charge');
        $project->feature('Billing', ['table:charges' => [$charge]]);
        $project->write('app/External/UnmappedOrder.php', <<<'PHP'
<?php
namespace Fixture\Unmapped;
class UnmappedOrder extends \Illuminate\Database\Eloquent\Model {}
PHP);
        $project->write('app/External/UnmappedRefund.php', <<<'PHP'
<?php
namespace Fixture\Unmapped;
class UnmappedRefund extends \Illuminate\Database\Eloquent\Model {}
PHP);
        $project->write('app/Features/Billing/Actions/Charge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
use Fixture\Unmapped\UnmappedOrder;
use Fixture\Unmapped\UnmappedRefund;
class Charge
{
    public function handle(UnmappedOrder $order, UnmappedRefund $refund): void
    {
        $order->save();
        $refund->save();
    }
}
PHP);
        $project->configure([[
            'rule' => 'architecture.writeLocation',
            'file' => 'app/Features/Billing/Actions/Charge.php',
            'target' => 'table:unknown',
            'model' => 'Fixture\\Unmapped\\UnmappedOrder',
            'reason' => 'The exact package-owned order model remains external.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ]]);

        $analysis = runOwnedWriteAnalysis($project);

        expect($analysis['exitCode'])->toBe(1, $analysis['stderr'].$analysis['stdout']);
        $report = decodeOwnedWriteReport($analysis['stdout']);
        expect($report['totals'])->toBe(['errors' => 0, 'file_errors' => 1])
            ->and($report['files'])->toHaveKey($project->path.'/app/Features/Billing/Actions/Charge.php')
            ->and(array_column($report['files'][$project->path.'/app/Features/Billing/Actions/Charge.php']['messages'], 'identifier'))
            ->toBe(['architecture.writeLocation']);
    } finally {
        $project->remove();
    }
});

it('keys an inherited token write exception to its trait source file', function (): void {
    $project = new FixtureProject;
    $project->feature('Identity');

    try {
        $project->write('app/Features/Identity/Support/HasUserRestoration.php', <<<'PHP'
<?php
namespace App\Features\Identity\Support;
trait HasUserRestoration
{
    protected function deleteAuthenticationState(): void
    {
        $this->tokens()->delete();
    }
}
PHP);
        $project->write('app/Models/User.php', <<<'PHP'
<?php
namespace App\Models;
use App\Features\Identity\Support\HasUserRestoration;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;
class User extends Model
{
    use HasApiTokens;
    use HasUserRestoration;

    protected $table = 'users';
}
PHP);

        $exception = [
            'rule' => 'architecture.writeLocation',
            'file' => 'app/Features/Identity/Support/HasUserRestoration.php',
            'target' => 'table:unknown',
            'model' => Laravel\Sanctum\PersonalAccessToken::class,
            'relation' => 'tokens',
            'relation_owner' => App\Models\User::class,
            'reason' => 'The exact Sanctum token relation remains in the account lifecycle trait.',
            'owner' => 'Identity',
            'expires' => '2999-12-31',
        ];
        $project->configure([$exception]);

        $traitException = runOwnedWriteAnalysis($project);

        expect($traitException['exitCode'])->toBe(0, $traitException['stderr'].$traitException['stdout'])
            ->and(decodeOwnedWriteReport($traitException['stdout'])['totals'])
            ->toBe(['errors' => 0, 'file_errors' => 0]);

        $exception['file'] = 'app/Models/User.php';
        $project->configure([$exception]);

        $modelException = runOwnedWriteAnalysis($project);

        expect($modelException['exitCode'])->toBe(1, $modelException['stderr'].$modelException['stdout'])
            ->and(decodeOwnedWriteReport($modelException['stdout'])['totals'])
            ->toBe(['errors' => 0, 'file_errors' => 1]);
    } finally {
        $project->remove();
    }
});

it('fails loudly when a write exception has expired', function (): void {
    $project = ownedWriteFixture();

    try {
        $project->configure([[
            'rule' => 'architecture.writeLocation',
            'file' => 'app/Features/Orders/EntryPoints/Unregistered.php',
            'target' => 'table:orders',
            'model' => 'App\\Features\\Orders\\Models\\Order',
            'reason' => 'Expired root adapter.',
            'owner' => 'Orders',
            'expires' => '2000-01-01',
        ]]);

        $analysis = runOwnedWriteAnalysis($project);

        expect($analysis['exitCode'])->not->toBe(0)
            ->and($analysis['stdout'].$analysis['stderr'])->toContain('Invalid/expired exception: app/Features/Orders/EntryPoints/Unregistered.php table:orders');
    } finally {
        $project->remove();
    }
});

it('rejects a concrete table exception without an exact model', function (): void {
    $project = ownedWriteFixture();

    try {
        $project->write('app/Features/Orders/EntryPoints/Unregistered.php', <<<'PHP'
<?php
namespace App\Features\Orders\EntryPoints;
use App\Features\Orders\Models\Order;
class Unregistered
{
    public function handle(Order $order): void
    {
        $order->save();
    }
}
PHP);
        $project->configure([[
            'rule' => 'architecture.writeLocation',
            'file' => 'app/Features/Orders/EntryPoints/Unregistered.php',
            'target' => 'table:orders',
            'reason' => 'A concrete table exception must remain scoped to one model.',
            'owner' => 'Orders',
            'expires' => '2999-12-31',
        ]]);

        $analysis = runOwnedWriteAnalysis($project);

        expect($analysis['exitCode'])->not->toBe(0)
            ->and($analysis['stdout'].$analysis['stderr'])->toContain('Table write exceptions require an exact model class');
    } finally {
        $project->remove();
    }
});

it('isolates concrete third-party model writes in Host adapters', function (): void {
    $project = ownedWriteFixture();

    try {
        $project->write('app/Host/Affiliates/ExternalAffiliateWriter.php', <<<'PHP'
<?php
namespace App\Host\Affiliates;
use AIArmada\Affiliates\Features\Identity\Models\Affiliate;
final class ExternalAffiliateWriter
{
    public function persist(Affiliate $affiliate): void { $affiliate->saveQuietly(); }
}
PHP);

        $analysis = runOwnedWriteAnalysis($project);
        expect($analysis['exitCode'])->toBe(0, $analysis['stderr'].$analysis['stdout'])
            ->and(decodeOwnedWriteReport($analysis['stdout'])['totals'])->toBe([
                'errors' => 0,
                'file_errors' => 0,
            ]);
    } finally {
        $project->remove();
    }
});

it('rejects Host mutations of application-owned models', function (): void {
    $project = ownedWriteFixture();

    try {
        $project->write('app/Host/Affiliates/UnauthorizedOrderWriter.php', <<<'PHP'
<?php
namespace App\Host\Affiliates;
use App\Features\Orders\Models\Order;
final class UnauthorizedOrderWriter
{
    public function persist(Order $order): void { $order->save(); }
}
PHP);

        $analysis = runOwnedWriteAnalysis($project);
        expect($analysis['exitCode'])->toBe(1, $analysis['stderr'].$analysis['stdout']);

        $report = decodeOwnedWriteReport($analysis['stdout']);
        $messages = $report['files'][$project->path.'/app/Host/Affiliates/UnauthorizedOrderWriter.php']['messages'] ?? [];
        expect(array_column($messages, 'identifier'))->toContain('architecture.foreignWrite');
    } finally {
        $project->remove();
    }
});
