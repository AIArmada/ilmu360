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
        class: LaravelAgentArchitecture\OwnedWriteRule
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
        '--autoload-file='.$root.'/tools/Architecture/OwnedWriteRule.php',
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
                'reason' => 'Documented root billing adapter.',
                'owner' => 'Billing',
                'expires' => '2999-12-31',
            ],
            [
                'rule' => 'architecture.writeLocation',
                'file' => 'app/Features/Orders/EntryPoints/Unregistered.php',
                'target' => 'table:orders',
                'reason' => 'Documented root orders adapter.',
                'owner' => 'Orders',
                'expires' => '2999-12-31',
            ],
        ]);

        $analysis = runOwnedWriteAnalysis($project);

        expect($analysis['exitCode'])->toBe(0, $analysis['stderr'].$analysis['stdout']);
        expect(decodeOwnedWriteReport($analysis['stdout'])['totals'])->toBe(['errors' => 0, 'file_errors' => 0]);
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
                'reason' => 'Wrong file.',
                'owner' => 'Billing',
                'expires' => '2999-12-31',
            ],
            [
                'rule' => 'architecture.writeLocation',
                'file' => 'app/Features/Orders/EntryPoints/Unregistered.php',
                'target' => 'table:charges',
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

it('fails loudly when a write exception has expired', function (): void {
    $project = ownedWriteFixture();

    try {
        $project->configure([[
            'rule' => 'architecture.writeLocation',
            'file' => 'app/Features/Orders/EntryPoints/Unregistered.php',
            'target' => 'table:orders',
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
