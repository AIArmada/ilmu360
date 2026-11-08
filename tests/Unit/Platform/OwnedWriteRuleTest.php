<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Fixtures\Architecture\FixtureProject;

it('permits owning and canonical non-runtime mutations while rejecting unregistered and foreign application writes', function (): void {
    $project = new FixtureProject;
    $root = dirname(__DIR__, 3);

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

        $project->write('phpstan.neon', <<<NEON
parameters:
    customRulesetUsed: true
    paths:
        - {$project->path}/app
        - {$project->path}/database
        - {$project->path}/tests
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

        expect($process->getExitCode())->toBe(1, $process->getErrorOutput().$process->getOutput());
        $output = $process->getOutput();
        $report = json_decode(substr($output, strpos($output, '{')), true, 512, JSON_THROW_ON_ERROR);
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
