<?php

declare(strict_types=1);

use Tests\Fixtures\Architecture\FixtureProject;

beforeEach(function (): void {
    $this->project = new FixtureProject;
    $place = $this->project->writer('Orders', 'PlaceOrder');
    $this->project->feature('Orders', ['table:orders' => [$place]]);
    $this->project->feature('Billing');
    // Even minimal projects need the conventional Fortify entrypoints
    // so the architecture checker can distinguish stale allowlist entries.
    foreach (['CreateNewUser', 'RecordSuccessfulLogin', 'ResetUserPassword'] as $entrypoint) {
        $this->project->write(
            "app/Platform/Auth/Fortify/{$entrypoint}.php",
            "<?php\nnamespace App\\Actions\\Fortify;\nclass {$entrypoint} {}\n",
        );
    }
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

it('rejects a stale Fortify framework adapter allowlist entry', function (): void {
    unlink($this->project->path.'/app/Platform/Auth/Fortify/RecordSuccessfulLogin.php');

    $result = $this->project->check();

    expect($result)->toMatchArray(['exitCode' => 1, 'stderr' => '']);

    $findings = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR)['findings'];

    expect(array_column($findings, 'rule'))->toBe(['stale-fortify-entrypoint'])
        ->and(array_column($findings, 'target'))->toBe(['app/Platform/Auth/Fortify/RecordSuccessfulLogin.php']);
});

it('allows foreign feature model type and relation references', function (string $source): void {
    $this->project->write('app/Features/Billing/Actions/Charge.php', $source);

    $result = $this->project->check();
    $findings = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR)['findings'] ?? [];

    expect($result['stderr'])->toBeEmpty()
        ->and(array_column($findings, 'rule'))->not->toContain('feature-private')
        ->and(array_column($findings, 'rule'))->not->toContain('foreign-model-domain-operation');
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

it('rejects cross-owner Eloquent model queries and construction', function (string $source): void {
    $this->project->write('app/Features/Billing/Actions/Charge.php', $source);

    $result = $this->project->check();
    expect($result)->toMatchArray(['exitCode' => 1, 'stderr' => '']);
    $findings = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR)['findings'];
    expect(array_column($findings, 'rule'))->toContain('foreign-model-domain-operation')
        ->and(array_column($findings, 'target'))->toContain('App\\Features\\Orders\\Models\\Order');
})->with([
    'static query' => [<<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class Charge
{
    public function handle(): void { Order::query()->first(); }
}
PHP],
    'static create' => [<<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class Charge
{
    public function handle(): void { Order::create(['id' => 'x']); }
}
PHP],
    'direct construction' => [<<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class Charge
{
    public function handle(): Order { return new Order; }
}
PHP],
]);

it('rejects platform imports of feature models', function (): void {
    $this->project->write('app/Platform/Admin/Widget.php', <<<'PHP'
<?php
namespace App\Platform\Admin;
class Widget
{
    public function handle(Order $order): void {}
}
PHP);

    $result = $this->project->check();
    expect($result)->toMatchArray(['exitCode' => 1, 'stderr' => '']);
    $findings = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR)['findings'];
    expect(array_column($findings, 'rule'))->toContain('platform-direction')
        ->and(array_column($findings, 'target'))->toContain('App\\Features\\Orders\\Models\\Order');
});

it('allows a feature to use an app model whose table it owns', function (): void {
    $this->project->write('app/Models/Order.php', <<<'PHP'
<?php
namespace App\Models;
class Order extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'orders';
}
PHP);
    $this->project->write('app/Features/Orders/Actions/PlaceOrder.php', <<<'PHP'
<?php
namespace App\Features\Orders\Actions;
class PlaceOrder
{
    public function handle(): void { Order::query()->create(['id' => 'order']); }
}
PHP);

    $result = $this->project->check();
    expect($result)->toMatchArray(['stderr' => '', 'exitCode' => 0])
        ->and(json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR)['findings'])->toBe([]);
});

it('accepts parent delete only from the exact declared root model', function (): void {
    $deleteSpace = $this->project->writer('Places', 'DeleteSpace');
    $deleteVenue = $this->project->writer('Places', 'DeleteVenue');
    $this->project->feature('Places', [
        'table:venue_spaces' => [$deleteSpace],
        'table:venues' => [$deleteVenue],
    ]);
    $this->project->write('app/Models/Space.php', <<<'PHP'
<?php
namespace App\Models;
class Space extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'venue_spaces';

    public function delete(): ?bool
    {
        return (function (): ?bool {
            return parent::delete();
        })();
    }
}
PHP);
    $this->project->write('app/Models/Venue.php', <<<'PHP'
<?php
namespace App\Models;
class Venue extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'venues';

    public function delete(): ?bool
    {
        return parent::delete();
    }
}
PHP);
    $this->project->configure([
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Models/Space.php',
            'target' => 'table:venue_spaces',
            'model' => App\Models\Space::class,
            'reason' => 'The exact root model preserves its owner-scoped delete lifecycle.',
            'owner' => 'Places',
            'expires' => '2999-12-31',
        ],
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Models/Venue.php',
            'target' => 'table:venues',
            'model' => App\Models\Venue::class,
            'reason' => 'The exact root model preserves its owner-scoped delete lifecycle.',
            'owner' => 'Places',
            'expires' => '2999-12-31',
        ],
    ]);

    $exactModels = $this->project->check();

    expect($exactModels['exitCode'])->toBe(0, $exactModels['stderr'].$exactModels['stdout'])
        ->and($exactModels['stderr'])->toBeEmpty();

    $this->project->write('app/Models/Space.php', <<<'PHP'
<?php
namespace App\Models;
class Space extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'venue_spaces';

    public function delete(): ?bool
    {
        $nested = new class extends \Illuminate\Database\Eloquent\Model {
            public function delete(): ?bool
            {
                return parent::delete();
            }
        };

        return false;
    }
}
PHP);

    $unrelatedParentDelete = $this->project->check();

    expect($unrelatedParentDelete['exitCode'])->toBe(2)
        ->and($unrelatedParentDelete['stderr'])->toContain('Remove stale exception: app/Models/Space.php table:venue_spaces');
});

it('rejects a feature dependency on an app model owned by another feature', function (): void {
    $this->project->write('app/Models/Order.php', <<<'PHP'
<?php
namespace App\Models;
class Order extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'orders';
}
PHP);
    $this->project->write('app/Features/Billing/Actions/Charge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class Charge
{
    public function handle(): void { Order::query()->create(['id' => 'order']); }
}
PHP);

    $result = $this->project->check();
    $findings = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR)['findings'];

    expect($result)->toMatchArray(['exitCode' => 1, 'stderr' => ''])
        ->and(array_column($findings, 'rule'))->toContain('feature-external')
        ->and(array_column($findings, 'target'))->toContain('App\\Models\\Order');
});

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

it('accepts owned-write exceptions only when the exact file still performs the declared write', function (): void {
    $this->project->write('app/External/PackageOrder.php', <<<'PHP'
<?php
namespace Fixture\Package;
class PackageOrder extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'orders';
}
PHP);
    $this->project->write('app/Features/Billing/Actions/Charge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class Charge
{
    public function handle(): void { PackageOrder::query()->create(['id' => 'order']); }
}
PHP);
    $this->project->write('app/Features/Orders/EntryPoints/Unregistered.php', <<<'PHP'
<?php
namespace App\Features\Orders\EntryPoints;
class Unregistered
{
    public function handle(PackageOrder $order): void { $order->update(['name' => 'Changed']); }
}
PHP);
    $this->project->configure([
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Features/Billing/Actions/Charge.php',
            'target' => 'table:orders',
            'model' => 'Fixture\\Package\\PackageOrder',
            'reason' => 'Documented cross-feature write consumed by the owned-write rule.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
        [
            'rule' => 'architecture.writeLocation',
            'file' => 'app/Features/Orders/EntryPoints/Unregistered.php',
            'target' => 'table:orders',
            'model' => 'Fixture\\Package\\PackageOrder',
            'reason' => 'Documented unregistered write consumed by the owned-write rule.',
            'owner' => 'Orders',
            'expires' => '2999-12-31',
        ],
    ]);

    $result = $this->project->check();

    expect($result)->toMatchArray(['exitCode' => 0, 'stderr' => ''])
        ->and(json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR)['findings'])->toBe([]);

    $this->project->write('app/Features/Billing/Actions/Charge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
use Illuminate\Support\Facades\DB;
class Charge
{
    public function handle(): void { DB::table('orders')->insert(['id' => 'order']); }
}
PHP);

    $result = $this->project->check();

    expect($result['exitCode'])->toBe(2)
        ->and($result['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/Charge.php table:orders');
});

it('accepts an exact table exception when its package model remains in the writer', function (): void {
    $this->project->write('app/External/PackageOrder.php', <<<'PHP'
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
    $this->project->write('app/Features/Billing/Actions/Charge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class Charge
{
    public function handle(PackageOrder $order): void { $order->save(); }
}
PHP);
    $this->project->configure([[
        'rule' => 'architecture.foreignWrite',
        'file' => 'app/Features/Billing/Actions/Charge.php',
        'target' => 'table:orders',
        'model' => 'Fixture\\Package\\PackageOrder',
        'reason' => 'The exact package model is written through this integration action.',
        'owner' => 'Billing',
        'expires' => '2999-12-31',
    ]]);

    $result = $this->project->check();

    expect($result)->toMatchArray(['exitCode' => 0, 'stderr' => ''])
        ->and(json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR)['findings'])->toBe([]);
});

it('accepts a static package model write inside a nested callback', function (): void {
    $this->project->feature('Billing');
    $this->project->write('app/External/PackageOrder.php', <<<'PHP'
<?php
namespace Fixture\Package;
class PackageOrder extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'orders';
}
PHP);
    $this->project->write('app/Features/Billing/Actions/Charge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
use App\Platform\OwnerContext;
use Illuminate\Support\Facades\DB;
class Charge
{
    public function handle(): void
    {
        DB::transaction(function (): void {
            OwnerContext::withOwner(null, fn (): PackageOrder => PackageOrder::query()->create(['id' => 'order']));
        });
    }
}
PHP);
    $this->project->configure([[
        'rule' => 'architecture.writeLocation',
        'file' => 'app/Features/Billing/Actions/Charge.php',
        'target' => 'table:unknown',
        'model' => 'Fixture\\Package\\PackageOrder',
        'reason' => 'The exact package model remains the receiver inside the scoped callback.',
        'owner' => 'Billing',
        'expires' => '2999-12-31',
    ]]);

    $result = $this->project->check();

    expect($result)->toMatchArray(['exitCode' => 0, 'stderr' => ''])
        ->and(json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR)['findings'])->toBe([]);
});

it('accepts an exact package model write in a root adapter', function (): void {
    $this->project->write('app/External/PackageOrder.php', <<<'PHP'
<?php
namespace Fixture\Package;
class PackageOrder extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'orders';
}
PHP);
    $this->project->write('app/Services/PackageOrderWriter.php', <<<'PHP'
<?php
namespace App\Services;
class PackageOrderWriter
{
    public function create(): void
    {
        PackageOrder::query()->create(['id' => 'order']);
    }
}
PHP);
    $this->project->configure([[
        'rule' => 'architecture.writeLocation',
        'file' => 'app/Services/PackageOrderWriter.php',
        'target' => 'table:unknown',
        'model' => 'Fixture\\Package\\PackageOrder',
        'reason' => 'This exact root adapter writes the package model it declares.',
        'owner' => 'Platform',
        'expires' => '2999-12-31',
    ]]);

    $result = $this->project->check();

    expect($result)->toMatchArray(['exitCode' => 0, 'stderr' => ''])
        ->and(json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR)['findings'])->toBe([]);
});

it('accepts a typed model captured by an explicit closure use list', function (): void {
    $this->project->write('app/External/PackageOrder.php', <<<'PHP'
<?php
namespace Fixture\Package;
class PackageOrder extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'orders';
}
PHP);
    $this->project->write('app/Features/Billing/Actions/Charge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
use AIArmada\CommerceSupport\Support\OwnerContext;
class Charge
{
    public function handle(PackageOrder $order): void
    {
        OwnerContext::withOwner(null, function () use ($order): void {
            $order->save();
        });
    }
}
PHP);
    $this->project->configure([[
        'rule' => 'architecture.foreignWrite',
        'file' => 'app/Features/Billing/Actions/Charge.php',
        'target' => 'table:orders',
        'model' => 'Fixture\\Package\\PackageOrder',
        'reason' => 'The exact package order model is written through this integration action.',
        'owner' => 'Billing',
        'expires' => '2999-12-31',
    ]]);

    $result = $this->project->check();

    expect($result)->toMatchArray(['exitCode' => 0, 'stderr' => ''])
        ->and(json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR)['findings'])->toBe([]);
});

it('tracks implicit arrow captures without inheriting evidence through a shadowing parameter', function (): void {
    $this->project->write('app/External/PackageOrder.php', <<<'PHP'
<?php
namespace Fixture\Package;
class PackageOrder extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'orders';
}
class Profile extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'profiles';
}
PHP);
    $this->project->write('app/Features/Billing/Actions/Charge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class Charge
{
    public function handle(PackageOrder $order): void
    {
        $callback = fn () => $order->save();
        $callback();
    }
}
PHP);
    $this->project->write('app/Features/Billing/Actions/ShadowCharge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class ShadowCharge
{
    public function handle(PackageOrder $order, Profile $profile): void
    {
        $callback = fn (Profile $order) => $order->delete();
        $callback($profile);
    }
}
PHP);
    $this->project->configure([
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Features/Billing/Actions/Charge.php',
            'target' => 'table:orders',
            'model' => 'Fixture\\Package\\PackageOrder',
            'reason' => 'The exact package order model remains captured by the writer.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Features/Billing/Actions/ShadowCharge.php',
            'target' => 'table:orders',
            'model' => 'Fixture\\Package\\PackageOrder',
            'reason' => 'Only a package order receiver can satisfy this exception.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
    ]);

    $result = $this->project->check();

    expect($result['exitCode'])->toBe(2)
        ->and($result['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/ShadowCharge.php table:orders')
        ->and($result['stderr'])->not->toContain('Remove stale exception: app/Features/Billing/Actions/Charge.php table:orders');
});

it('rejects a closure capture unrelated to the declared model write', function (): void {
    $this->project->write('app/External/PackageOrder.php', <<<'PHP'
<?php
namespace Fixture\Package;
class PackageOrder extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'orders';
}
PHP);
    $this->project->write('app/Features/Billing/Actions/Charge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class Charge
{
    public function handle(PackageOrder $order, object $profile): void
    {
        (function () use ($profile): void {
            $profile->delete();
        })();
    }
}
PHP);
    $this->project->configure([[
        'rule' => 'architecture.foreignWrite',
        'file' => 'app/Features/Billing/Actions/Charge.php',
        'target' => 'table:orders',
        'model' => 'Fixture\\Package\\PackageOrder',
        'reason' => 'The exact package order model is written through this integration action.',
        'owner' => 'Billing',
        'expires' => '2999-12-31',
    ]]);

    $result = $this->project->check();

    expect($result['exitCode'])->toBe(2)
        ->and($result['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/Charge.php table:orders');
});

it('consumes unknown package-model exceptions from the actual trait source only', function (): void {
    $this->project->feature('Identity');
    $this->project->write('app/Features/Identity/Support/HasUserRestoration.php', <<<'PHP'
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
    $this->project->write('app/Models/User.php', <<<'PHP'
<?php
namespace App\Models;
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
        'model_provider' => 'sanctum',
        'relation' => 'tokens',
        'relation_owner' => App\Models\User::class,
        'reason' => 'The exact Sanctum token relation remains in the account lifecycle trait.',
        'owner' => 'Identity',
        'expires' => '2999-12-31',
    ];
    $this->project->configure([$exception]);

    $traitException = $this->project->check();

    expect($traitException['exitCode'])->toBe(0, $traitException['stderr'].$traitException['stdout'])
        ->and($traitException['stderr'])->toBeEmpty()
        ->and(json_decode($traitException['stdout'], true, 512, JSON_THROW_ON_ERROR)['findings'])->toBe([]);

    $exception['file'] = 'app/Models/User.php';
    $exception['model'] = 'Fixture\\Package\\UserToken';
    unset($exception['model_provider']);
    $this->project->configure([$exception]);

    $modelException = $this->project->check();

    expect($modelException['exitCode'])->toBe(2)
        ->and($modelException['stderr'])->toContain('Remove stale exception: app/Models/User.php table:unknown');
});

it('rejects a co-located unused trait borrowing a relation owner used by another trait', function (): void {
    $this->project->write('app/Models/Concerns/CombinedUserTraits.php', <<<'PHP'
<?php
namespace App\Models\Concerns;
trait HasUserRestoration
{
    protected function restoreAuthenticationState(): void {}
}
trait UnusedTokenCleanup
{
    protected function deleteAuthenticationState(): void
    {
        $this->tokens()->delete();
    }
}
PHP);
    $this->project->write('app/Models/User.php', <<<'PHP'
<?php
namespace App\Models;
use Laravel\Sanctum\HasApiTokens;
class User extends \Illuminate\Database\Eloquent\Model
{
    use HasApiTokens;
    use HasUserRestoration;
}
PHP);
    $this->project->configure([[
        'rule' => 'architecture.writeLocation',
        'file' => 'app/Models/Concerns/CombinedUserTraits.php',
        'target' => 'table:unknown',
        'model' => 'Fixture\\Package\\UserToken',
        'relation' => 'tokens',
        'relation_owner' => App\Models\User::class,
        'reason' => 'Only the trait actually used by User may own its token relation write.',
        'owner' => 'Identity',
        'expires' => '2999-12-31',
    ]]);

    $result = $this->project->check();

    expect($result['exitCode'])->toBe(2)
        ->and($result['stderr'])->toContain('Remove stale exception: app/Models/Concerns/CombinedUserTraits.php table:unknown');
});

it('rejects custom literal Sanctum token model overrides', function (): void {
    $this->project->write('app/Providers/TokenProvider.php', <<<'PHP'
<?php
namespace App\Providers;
use Fixture\CustomPersonalAccessToken;
use Laravel\Sanctum\Sanctum;
class TokenProvider
{
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(CustomPersonalAccessToken::class);
    }
}
PHP);

    $result = $this->project->check();

    expect($result['exitCode'])->toBe(2)
        ->and($result['stderr'])->toContain('The application Sanctum token model must remain Laravel\\Sanctum\\PersonalAccessToken.');
});

it('rejects unresolved dynamic Sanctum token model overrides', function (): void {
    $this->project->write('app/Providers/TokenProvider.php', <<<'PHP'
<?php
namespace App\Providers;
use Laravel\Sanctum\Sanctum;
class TokenProvider
{
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(config('auth.personal_access_token_model'));
    }
}
PHP);

    $result = $this->project->check();

    expect($result['exitCode'])->toBe(2)
        ->and($result['stderr'])->toContain('Sanctum personal access token model registration must use one exact literal class');
});

it('rejects multiple Sanctum token model registrations', function (): void {
    $this->project->write('app/Providers/TokenProvider.php', <<<'PHP'
<?php
namespace App\Providers;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
class TokenProvider
{
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
    }
}
PHP);

    $result = $this->project->check();

    expect($result['exitCode'])->toBe(2)
        ->and($result['stderr'])->toContain('Multiple Sanctum personal access token model registrations found');
});

it('rejects Sanctum model metadata on an unrelated table target', function (): void {
    $this->project->configure([[
        'rule' => 'architecture.writeLocation',
        'file' => 'app/Features/Identity/Support/HasUserRestoration.php',
        'target' => 'table:users',
        'model' => Laravel\Sanctum\PersonalAccessToken::class,
        'model_provider' => 'sanctum',
        'relation' => 'tokens',
        'relation_owner' => App\Models\User::class,
        'reason' => 'Sanctum metadata must stay scoped to token table exceptions.',
        'owner' => 'Identity',
        'expires' => '2999-12-31',
    ]]);

    $result = $this->project->check();

    expect($result['exitCode'])->toBe(2)
        ->and($result['stderr'])->toContain('Sanctum token model metadata is only valid on its exact User token exception.');
});

it('accepts a package model exception when the exact relation remains in the writer', function (): void {
    $this->project->write('app/External/Bookmark.php', <<<'PHP'
<?php
namespace Fixture\Package;
class Bookmark extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'bookmarks';
}
PHP);
    $this->project->write('app/External/BookmarkOwner.php', <<<'PHP'
<?php
namespace Fixture\Relations;
class BookmarkOwner {}
PHP);
    $this->project->write('app/Features/Billing/Actions/Charge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class Charge
{
    public function handle(\Fixture\Relations\BookmarkOwner $event): void { $event->savedBy()->delete(); }
}
PHP);

    $this->project->configure([[
        'rule' => 'architecture.writeLocation',
        'file' => 'app/Features/Billing/Actions/Charge.php',
        'target' => 'table:bookmarks',
        'model' => 'Fixture\\Package\\Bookmark',
        'relation' => 'goingBy',
        'relation_owner' => 'Fixture\\Relations\\BookmarkOwner',
        'reason' => 'The exact package relation owns this scoped write.',
        'owner' => 'Billing',
        'expires' => '2999-12-31',
    ]]);

    $stale = $this->project->check();

    expect($stale['exitCode'])->toBe(2)
        ->and($stale['stderr'])->toContain('Remove stale exception');

    $this->project->configure([[
        'rule' => 'architecture.writeLocation',
        'file' => 'app/Features/Billing/Actions/Charge.php',
        'target' => 'table:bookmarks',
        'model' => 'Fixture\\Package\\Bookmark',
        'relation' => 'savedBy',
        'relation_owner' => 'Fixture\\Relations\\BookmarkOwner',
        'reason' => 'The exact package relation owns this scoped write.',
        'owner' => 'Billing',
        'expires' => '2999-12-31',
    ]]);

    $result = $this->project->check();

    expect($result)->toMatchArray(['exitCode' => 0, 'stderr' => ''])
        ->and(json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR)['findings'])->toBe([]);
});

it('consumes a relation exception for callback writes from a local owner assignment', function (): void {
    $this->project->write('app/External/Bookmark.php', <<<'PHP'
<?php
namespace Fixture\Package;
class Bookmark extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'bookmarks';
}
PHP);
    $this->project->write('app/External/BookmarkOwner.php', <<<'PHP'
<?php
namespace Fixture\Relations;
class BookmarkOwner extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'bookmark_owners';
}
PHP);
    $this->project->write('app/Features/Billing/Actions/CleanupBookmarks.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class CleanupBookmarks
{
    public function handle(): void
    {
        $owner = \Fixture\Relations\BookmarkOwner::query()->first();
        $owner->savedBy()->each(static function ($bookmark): void {
            $bookmark->delete();
        });
    }
}
PHP);
    $this->project->configure([[
        'rule' => 'architecture.writeLocation',
        'file' => 'app/Features/Billing/Actions/CleanupBookmarks.php',
        'target' => 'table:bookmarks',
        'model' => 'Fixture\\Package\\Bookmark',
        'relation' => 'savedBy',
        'relation_owner' => 'Fixture\\Relations\\BookmarkOwner',
        'reason' => 'The exact package relation owns this scoped callback write.',
        'owner' => 'Billing',
        'expires' => '2999-12-31',
    ]]);

    $result = $this->project->check();

    expect($result)->toMatchArray(['exitCode' => 0, 'stderr' => ''])
        ->and($result['stderr'])->toBeEmpty()
        ->and(json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR)['findings'])->toBe([]);
});

it('rejects a model exception when only the model type and an unrelated delete remain', function (): void {
    $this->project->write('app/External/PackageOrder.php', <<<'PHP'
<?php
namespace Fixture\Package;
class PackageOrder extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'orders';
}
PHP);
    $this->project->write('app/Features/Billing/Actions/Charge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class Charge
{
    public function handle(PackageOrder $order, object $profile): void
    {
        $profile->delete();
    }
}
PHP);
    $this->project->configure([[
        'rule' => 'architecture.foreignWrite',
        'file' => 'app/Features/Billing/Actions/Charge.php',
        'target' => 'table:orders',
        'model' => 'Fixture\\Package\\PackageOrder',
        'reason' => 'The exact package order model is written through this integration action.',
        'owner' => 'Billing',
        'expires' => '2999-12-31',
    ]]);

    $result = $this->project->check();

    expect($result['exitCode'])->toBe(2)
        ->and($result['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/Charge.php table:orders');
});

it('rejects model-return provenance borrowed from an unrelated receiver', function (): void {
    $this->project->write('app/External/PackageOrder.php', <<<'PHP'
<?php
namespace Fixture\Package;
class PackageOrder extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'orders';
}
PHP);
    $this->project->write('app/Features/Billing/Actions/Charge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class Charge
{
    private function order(): PackageOrder { return new PackageOrder; }

    public function handle(object $other): void
    {
        $other->order()->delete();
    }
}
PHP);
    $this->project->configure([[
        'rule' => 'architecture.foreignWrite',
        'file' => 'app/Features/Billing/Actions/Charge.php',
        'target' => 'table:orders',
        'model' => 'Fixture\\Package\\PackageOrder',
        'reason' => 'The exact package order model is written through this integration action.',
        'owner' => 'Billing',
        'expires' => '2999-12-31',
    ]]);

    $result = $this->project->check();

    expect($result['exitCode'])->toBe(2)
        ->and($result['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/Charge.php table:orders');
});

it('does not borrow local model return provenance from another class in the same file', function (): void {
    $this->project->write('app/External/PackageOrder.php', <<<'PHP'
<?php
namespace Fixture\Package;
class PackageOrder extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'orders';
}
PHP);
    $this->project->write('app/Features/Billing/Actions/Charge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class Charge
{
    public function handle(): void
    {
        $this->order()->delete();
    }
}
class Decoy
{
    private function order(): PackageOrder
    {
        return new PackageOrder;
    }
}
PHP);
    $this->project->configure([[
        'rule' => 'architecture.foreignWrite',
        'file' => 'app/Features/Billing/Actions/Charge.php',
        'target' => 'table:orders',
        'model' => 'Fixture\\Package\\PackageOrder',
        'reason' => 'The exact package order model is written through this integration action.',
        'owner' => 'Billing',
        'expires' => '2999-12-31',
    ]]);

    $result = $this->project->check();

    expect($result['exitCode'])->toBe(2)
        ->and($result['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/Charge.php table:orders');
});

it('reports all stale write exceptions when PHPDoc return provenance is not exact', function (): void {
    $this->project->write('app/External/PackageOrder.php', <<<'PHP'
<?php
namespace Fixture\Package;
class PackageOrder extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'orders';
}
class PackageOrderArchive extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'orders';
}
class InvoiceOrder extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'invoices';
}
PHP);
    $this->project->write('app/Features/Billing/Actions/Charge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class Charge
{
    /** @return \Fixture\Package\PackageOrderArchive */
    private function order()
    {
        return new \Fixture\Package\PackageOrderArchive;
    }

    /** @return \Illuminate\Support\Collection<int, \Fixture\Package\InvoiceOrder> */
    private function invoices()
    {
        return collect();
    }

    public function handle(): void
    {
        $this->order()->delete();
        $this->invoices()->delete();
    }
}
PHP);
    $this->project->configure([
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Features/Billing/Actions/Charge.php',
            'target' => 'table:orders',
            'model' => 'Fixture\\Package\\PackageOrder',
            'reason' => 'The exact package order model remains in this writer.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Features/Billing/Actions/Charge.php',
            'target' => 'table:invoices',
            'model' => 'Fixture\\Package\\InvoiceOrder',
            'reason' => 'The exact invoice order model remains in this writer.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
    ]);

    $result = $this->project->check();

    expect($result['exitCode'])->toBe(2)
        ->and($result['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/Charge.php table:orders')
        ->and($result['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/Charge.php table:invoices');
});

it('accepts exact Eloquent Builder generic returns for model provenance', function (): void {
    $this->project->write('app/External/TitleAssignment.php', <<<'PHP'
<?php
namespace Fixture\Package;
class TitleAssignment extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'title_assignments';
}
PHP);
    $titleWriter = $this->project->writer('Titles', 'CreateTitleAssignment');
    $this->project->feature('Titles', ['table:title_assignments' => [$titleWriter]]);
    $this->project->write('app/Features/Billing/Actions/BuilderAliasCharge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
class BuilderAliasCharge
{
    /** @return EloquentBuilder<TitleAssignment> */
    private function assignmentsQuery()
    {
        return TitleAssignment::query();
    }

    public function handle(): void
    {
        $this->assignmentsQuery()->create(['title' => 'New']);
    }
}
PHP);
    $this->project->write('app/Features/Billing/Actions/BuilderFqcnCharge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class BuilderFqcnCharge
{
    /** @return \Illuminate\Database\Eloquent\Builder<\Fixture\Package\TitleAssignment> */
    private function assignmentsQuery()
    {
        return \Fixture\Package\TitleAssignment::query();
    }

    public function handle(): void
    {
        $this->assignmentsQuery()->create(['title' => 'New']);
    }
}
PHP);
    $this->project->configure([
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Features/Billing/Actions/BuilderAliasCharge.php',
            'target' => 'table:title_assignments',
            'model' => 'Fixture\\Package\\TitleAssignment',
            'reason' => 'The exact imported Builder generic identifies the written model.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Features/Billing/Actions/BuilderFqcnCharge.php',
            'target' => 'table:title_assignments',
            'model' => 'Fixture\\Package\\TitleAssignment',
            'reason' => 'The exact fully qualified Builder generic identifies the written model.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
    ]);

    $result = $this->project->check();
    expect($result)->toMatchArray(['stderr' => '', 'exitCode' => 0])
        ->and(json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR)['findings'])->toBe([]);
});

it('does not treat model builders as relation owners when both roles share a class', function (): void {
    $this->project->write('app/External/TitleAssignment.php', <<<'PHP'
<?php
namespace Fixture\Package;
class TitleAssignment extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'title_assignments';
}
PHP);
    $titleWriter = $this->project->writer('Titles', 'CreateTitleAssignment');
    $this->project->feature('Titles', ['table:title_assignments' => [$titleWriter]]);
    $this->project->write('app/Features/Billing/Actions/BuilderRelationCharge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
use Illuminate\Database\Eloquent\Builder;
class BuilderRelationCharge
{
    /** @return Builder<TitleAssignment> */
    private function assignmentsQuery()
    {
        return TitleAssignment::query();
    }

    public function handle(): void
    {
        $this->assignmentsQuery()->titleAssignments()->create(['title' => 'New']);
    }
}
PHP);
    $this->project->configure([[
        'rule' => 'architecture.foreignWrite',
        'file' => 'app/Features/Billing/Actions/BuilderRelationCharge.php',
        'target' => 'table:title_assignments',
        'model' => 'Fixture\\Package\\TitleAssignment',
        'relation' => 'titleAssignments',
        'relation_owner' => 'Fixture\\Package\\TitleAssignment',
        'reason' => 'The relation must be called on an exact relation owner.',
        'owner' => 'Billing',
        'expires' => '2999-12-31',
    ]]);

    $result = $this->project->check();

    expect($result['exitCode'])->toBe(2)
        ->and($result['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/BuilderRelationCharge.php table:title_assignments model Fixture\\Package\\TitleAssignment');
});

it('accepts an exact imported PHPDoc return type for model provenance', function (): void {
    $this->project->write('app/External/PackageOrder.php', <<<'PHP'
<?php
namespace Fixture\Package;
class PackageOrder extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'orders';
}
PHP);
    $this->project->write('app/Features/Billing/Actions/Charge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
use Fixture\Package\PackageOrder as OrderRecord;
class Charge
{
    /** @return OrderRecord */
    private function order()
    {
        return new OrderRecord;
    }

    /** @return \Fixture\Package\PackageOrder */
    private function latestOrder()
    {
        return new OrderRecord;
    }

    public function handle(): void
    {
        $this->order()->delete();
        $this->latestOrder()->delete();
    }
}
PHP);
    $this->project->configure([[
        'rule' => 'architecture.foreignWrite',
        'file' => 'app/Features/Billing/Actions/Charge.php',
        'target' => 'table:orders',
        'model' => 'Fixture\\Package\\PackageOrder',
        'reason' => 'The exact package order model remains in this writer.',
        'owner' => 'Billing',
        'expires' => '2999-12-31',
    ]]);

    $result = $this->project->check();

    expect($result)->toMatchArray(['exitCode' => 0, 'stderr' => ''])
        ->and(json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR)['findings'])->toBe([]);
});

it('does not borrow return-variable provenance across branches or reassignments', function (): void {
    $this->project->write('app/External/PackageOrder.php', <<<'PHP'
<?php
namespace Fixture\Package;
class PackageOrder extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'orders';
}
class Profile extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'profiles';
}
PHP);
    $this->project->write('app/Features/Billing/Actions/Charge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class Charge
{
    private function updateProfileOrReturnOrder(bool $returnOrder): PackageOrder
    {
        if ($returnOrder) {
            $record = new PackageOrder;
            return $record;
        }

        $record = new Profile;
        $record->delete();

        return new PackageOrder;
    }
}
PHP);
    $this->project->write('app/Features/Billing/Actions/AncestorCharge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class AncestorCharge
{
    private function returnOrderAfterConditionalWrite(object $providedRecord, bool $write): PackageOrder
    {
        $record = $providedRecord;

        if ($write) {
            $record->save();
        }

        return $record;
    }

    private function returnOrderFromSameArm(object $providedRecord, bool $write): PackageOrder
    {
        if ($write) {
            $record = $providedRecord;
            $record->save();

            return $record;
        }

        return new PackageOrder;
    }
}
PHP);
    $this->project->write('app/Features/Billing/Actions/BranchLeakCharge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class BranchLeakCharge
{
    public function handle(object $providedRecord, bool $writeOrder): void
    {
        $record = $providedRecord;

        if ($writeOrder) {
            $record = new PackageOrder;
        } else {
            $record->delete();
        }
    }
}
PHP);
    $this->project->write('app/Features/Billing/Actions/ElseIfLeakCharge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class ElseIfLeakCharge
{
    public function handle(object $providedRecord, int $branch): void
    {
        $record = $providedRecord;

        if ($branch === 0) {
            return;
        } elseif ($branch === 1) {
            $record = new PackageOrder;
        } elseif ($branch === 2) {
            $record->delete();
        }
    }
}
PHP);
    $this->project->write('app/Features/Billing/Actions/JoinLeakCharge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class JoinLeakCharge
{
    public function handle(bool $replace): void
    {
        $record = new PackageOrder;

        if ($replace) {
            $record = new Profile;
        }

        $record->delete();
    }
}
PHP);
    $this->project->write('app/Features/Billing/Actions/ReferenceCaptureCharge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class ReferenceCaptureCharge
{
    public function handle(PackageOrder $order): void
    {
        $record = $order;
        $callback = function () use (&$record): void {
            $record->delete();
        };

        $record = new Profile;
        $callback();
    }
}
PHP);
    $this->project->write('app/Features/Billing/Actions/SiblingFlowCharge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class SiblingFlowCharge
{
    public function handle(PackageOrder $order, bool $replace): void
    {
        $record = $order;

        if ($replace) {
            $record = new Profile;
        } else {
            $record->delete();
        }
    }
}
PHP);
    $this->project->write('app/Features/Billing/Actions/TryCatchLeakCharge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class TryCatchLeakCharge
{
    public function handle(): void
    {
        $record = new PackageOrder;

        try {
            $record = new Profile;
            throw new \RuntimeException;
        } catch (\RuntimeException) {
            $record->delete();
        }
    }
}
PHP);
    $this->project->write('app/Features/Billing/Actions/TryFinallyLeakCharge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class TryFinallyLeakCharge
{
    public function handle(): void
    {
        $record = new PackageOrder;

        try {
            $record = new Profile;
        } finally {
            $record->delete();
        }
    }
}
PHP);
    $this->project->write('app/Features/Billing/Actions/CatchFinallyLeakCharge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class CatchFinallyLeakCharge
{
    public function handle(): void
    {
        $record = new PackageOrder;

        try {
            throw new \RuntimeException;
        } catch (\RuntimeException) {
            $record = new Profile;
        } finally {
            $record->delete();
        }
    }
}
PHP);
    $this->project->write('app/Features/Billing/Actions/CatchSiblingLeakCharge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class CatchSiblingLeakCharge
{
    public function handle(object $providedRecord, bool $first): void
    {
        $record = $providedRecord;

        try {
            if ($first) {
                throw new \InvalidArgumentException;
            }

            throw new \RuntimeException;
        } catch (\InvalidArgumentException) {
            $record = new PackageOrder;
        } catch (\RuntimeException) {
            $record->delete();
        }
    }
}
PHP);
    $this->project->write('app/Features/Billing/Actions/SwitchFallthroughLeakCharge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class SwitchFallthroughLeakCharge
{
    public function handle(int $case): void
    {
        $record = new PackageOrder;

        switch ($case) {
            case 1:
                $record = new Profile;
            case 2:
                $record->delete();
                break;
            default:
                break;
        }
    }
}
PHP);
    $this->project->write('app/Features/Billing/Actions/SwitchBreakFlowCharge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class SwitchBreakFlowCharge
{
    public function handle(int $case): void
    {
        $record = new PackageOrder;

        switch ($case) {
            case 1:
                $record = new Profile;
                break;
            case 2:
                $record->delete();
                break;
            default:
                break;
        }
    }
}
PHP);
    $this->project->write('app/Features/Billing/Actions/HeaderLeakCharge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class HeaderLeakCharge
{
    public function handle(): void
    {
        $record = new PackageOrder;

        if ($record = new Profile) {
            $record->delete();
        }
    }
}
PHP);
    $this->project->write('app/Features/Billing/Actions/TernaryLeakCharge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class TernaryLeakCharge
{
    public function handle(bool $isOrder): void
    {
        $record = $isOrder ? new PackageOrder : new Profile;
        $record->delete();
    }
}
PHP);
    $this->project->write('app/Features/Billing/Actions/TernarySameModelCharge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class TernarySameModelCharge
{
    public function handle(bool $isOrder): void
    {
        $record = $isOrder ? new PackageOrder : new PackageOrder;
        $record->delete();
    }
}
PHP);
    $this->project->write('app/Features/Billing/Actions/ElseIfConditionLeakCharge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class ElseIfConditionLeakCharge
{
    public function handle(bool $first, bool $stop, bool $later): void
    {
        $record = new PackageOrder;

        if ($first) {
            return;
        } elseif (($record = new Profile) && $stop) {
            return;
        } elseif ($later) {
            $record->delete();
        }
    }
}
PHP);
    $this->project->write('app/Features/Billing/Actions/SwitchNestedFallthroughLeakCharge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class SwitchNestedFallthroughLeakCharge
{
    public function handle(int $outer, int $inner): void
    {
        $record = new PackageOrder;

        switch ($outer) {
            case 1:
                $record = new Profile;
            case 2:
                switch ($inner) {
                    case 3:
                        $record->delete();
                        break;
                }
                break;
            default:
                break;
        }
    }
}
PHP);
    $this->project->write('app/Features/Billing/Actions/SwitchLabelLeakCharge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class SwitchLabelLeakCharge
{
    public function handle(int $case): void
    {
        $record = new PackageOrder;

        switch ($case) {
            case ($record = new Profile) && false:
                break;
            case 2:
                $record->delete();
                break;
            default:
                break;
        }
    }
}
PHP);
    $this->project->write('app/Features/Billing/Actions/SwitchThrowFlowCharge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class SwitchThrowFlowCharge
{
    public function handle(int $case): void
    {
        $record = new PackageOrder;

        switch ($case) {
            case 1:
                $record = new Profile;
                throw new \RuntimeException;
            case 2:
                $record->delete();
                break;
            default:
                break;
        }
    }
}
PHP);
    $this->project->write('app/Features/Billing/Actions/SequentialIfLeakCharge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class SequentialIfLeakCharge
{
    public function handle(bool $replace, bool $write): void
    {
        $record = new PackageOrder;

        if ($replace) {
            $record = new Profile;
        }

        if ($write) {
            $record->delete();
        }
    }
}
PHP);
    $this->project->write('app/Features/Billing/Actions/LoopThenIfLeakCharge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class LoopThenIfLeakCharge
{
    public function handle(array $items, bool $write): void
    {
        $record = new PackageOrder;

        foreach ($items as $item) {
            $record = new Profile;
        }

        if ($write) {
            $record->delete();
        }
    }
}
PHP);
    $this->project->write('app/Features/Billing/Actions/SwitchThenIfLeakCharge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class SwitchThenIfLeakCharge
{
    public function handle(int $case, bool $write): void
    {
        $record = new PackageOrder;

        switch ($case) {
            case 1:
                $record = new Profile;
                break;
            default:
                break;
        }

        if ($write) {
            $record->delete();
        }
    }
}
PHP);
    $this->project->write('app/Features/Billing/Actions/DoConditionLeakCharge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class DoConditionLeakCharge
{
    public function handle(): void
    {
        $record = new PackageOrder;

        do {
            $record = new Profile;
        } while ($record->delete());
    }
}
PHP);
    $this->project->write('app/Features/Billing/Actions/WhileConditionLeakCharge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class WhileConditionLeakCharge
{
    public function handle(): void
    {
        $record = new PackageOrder;

        while ($record->delete()) {
            $record = new Profile;
        }
    }
}
PHP);
    $this->project->write('app/Features/Billing/Actions/ForConditionLeakCharge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class ForConditionLeakCharge
{
    public function handle(): void
    {
        $record = new PackageOrder;

        for (; $record->delete();) {
            $record = new Profile;
        }
    }
}
PHP);
    $this->project->write('app/Features/Billing/Actions/ForUpdateLeakCharge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class ForUpdateLeakCharge
{
    public function handle(bool $continue): void
    {
        $record = new PackageOrder;

        for (; $continue; $record->delete()) {
            $record = new Profile;
        }
    }
}
PHP);
    $this->project->write('app/Features/Billing/Actions/ForeachRepeatBodyLeakCharge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class ForeachRepeatBodyLeakCharge
{
    public function handle(array $items): void
    {
        $record = new PackageOrder;

        foreach ($items as $item) {
            $record->delete();
            $record = new Profile;
        }
    }
}
PHP);
    $this->project->write('app/Features/Billing/Actions/WhileRepeatBodyLeakCharge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class WhileRepeatBodyLeakCharge
{
    public function handle(bool $continue): void
    {
        $record = new PackageOrder;

        while ($continue) {
            $record->delete();
            $record = new Profile;
        }
    }
}
PHP);
    $this->project->write('app/Features/Billing/Actions/ForRepeatBodyLeakCharge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class ForRepeatBodyLeakCharge
{
    public function handle(int $count): void
    {
        $record = new PackageOrder;

        for ($index = 0; $index < $count; $index++) {
            $record->delete();
            $record = new Profile;
        }
    }
}
PHP);
    $this->project->write('app/Features/Billing/Actions/DoRepeatBodyLeakCharge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class DoRepeatBodyLeakCharge
{
    public function handle(bool $continue): void
    {
        $record = new PackageOrder;

        do {
            $record->delete();
            $record = new Profile;
        } while ($continue);
    }
}
PHP);
    $this->project->write('app/Features/Billing/Actions/DoConditionNextBodyLeakCharge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class DoConditionNextBodyLeakCharge
{
    public function handle(bool $continue): void
    {
        $record = new PackageOrder;

        do {
            $record->delete();
        } while (($record = new Profile) && $continue);
    }
}
PHP);
    $this->project->write('app/Features/Billing/Actions/NestedLoopForUpdateLeakCharge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class NestedLoopForUpdateLeakCharge
{
    public function handle(array $items, bool $continue): void
    {
        $record = new PackageOrder;

        for (; $continue; $record->delete()) {
            foreach ($items as $item) {
                $record = new Profile;
            }
        }
    }
}
PHP);
    $this->project->write('app/Features/Billing/Actions/SiblingLoopIdentityCharge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class SiblingLoopIdentityCharge
{
    public function handle(array $first, array $second): void
    {
        $record = new PackageOrder;

        foreach ($first as $item) {
            $record->delete();
        }

        foreach ($second as $item) {
            $record = new Profile;
        }
    }
}
PHP);
    $this->project->configure([
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Features/Billing/Actions/Charge.php',
            'target' => 'table:orders',
            'model' => 'Fixture\\Package\\PackageOrder',
            'reason' => 'The exact package order model remains in this writer.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Features/Billing/Actions/AncestorCharge.php',
            'target' => 'table:orders',
            'model' => 'Fixture\\Package\\PackageOrder',
            'reason' => 'The matching branch writes the exact returned package order.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Features/Billing/Actions/BranchLeakCharge.php',
            'target' => 'table:orders',
            'model' => 'Fixture\\Package\\PackageOrder',
            'reason' => 'An assignment from a sibling arm cannot prove this receiver.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Features/Billing/Actions/ElseIfLeakCharge.php',
            'target' => 'table:orders',
            'model' => 'Fixture\\Package\\PackageOrder',
            'reason' => 'A separate elseif arm cannot prove this receiver.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Features/Billing/Actions/JoinLeakCharge.php',
            'target' => 'table:orders',
            'model' => 'Fixture\\Package\\PackageOrder',
            'reason' => 'A nested-arm reassignment invalidates provenance after the join.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Features/Billing/Actions/ReferenceCaptureCharge.php',
            'target' => 'table:orders',
            'model' => 'Fixture\\Package\\PackageOrder',
            'reason' => 'A by-reference closure may observe a later receiver reassignment.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Features/Billing/Actions/SiblingFlowCharge.php',
            'target' => 'table:orders',
            'model' => 'Fixture\\Package\\PackageOrder',
            'reason' => 'A mutually exclusive arm cannot overwrite the outer order value.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Features/Billing/Actions/TryCatchLeakCharge.php',
            'target' => 'table:orders',
            'model' => 'Fixture\\Package\\PackageOrder',
            'reason' => 'A try-body reassignment can reach its catch body.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Features/Billing/Actions/TryFinallyLeakCharge.php',
            'target' => 'table:orders',
            'model' => 'Fixture\\Package\\PackageOrder',
            'reason' => 'A try-body reassignment can reach finally.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Features/Billing/Actions/CatchFinallyLeakCharge.php',
            'target' => 'table:orders',
            'model' => 'Fixture\\Package\\PackageOrder',
            'reason' => 'A catch-body reassignment can reach finally.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Features/Billing/Actions/CatchSiblingLeakCharge.php',
            'target' => 'table:orders',
            'model' => 'Fixture\\Package\\PackageOrder',
            'reason' => 'A separate catch arm cannot prove the receiver.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Features/Billing/Actions/SwitchFallthroughLeakCharge.php',
            'target' => 'table:orders',
            'model' => 'Fixture\\Package\\PackageOrder',
            'reason' => 'A switch case may fall through into a later case.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Features/Billing/Actions/SwitchBreakFlowCharge.php',
            'target' => 'table:orders',
            'model' => 'Fixture\\Package\\PackageOrder',
            'reason' => 'A break prevents a case reassignment from reaching its sibling.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Features/Billing/Actions/HeaderLeakCharge.php',
            'target' => 'table:orders',
            'model' => 'Fixture\\Package\\PackageOrder',
            'reason' => 'The condition assignment runs before the entered body.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Features/Billing/Actions/TernaryLeakCharge.php',
            'target' => 'table:orders',
            'model' => 'Fixture\\Package\\PackageOrder',
            'reason' => 'A ternary with a different model arm does not guarantee this model.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Features/Billing/Actions/TernarySameModelCharge.php',
            'target' => 'table:orders',
            'model' => 'Fixture\\Package\\PackageOrder',
            'reason' => 'Every ternary arm returns this exact model.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Features/Billing/Actions/ElseIfConditionLeakCharge.php',
            'target' => 'table:orders',
            'model' => 'Fixture\\Package\\PackageOrder',
            'reason' => 'A false earlier elseif condition can carry its assignment into a later arm.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Features/Billing/Actions/SwitchNestedFallthroughLeakCharge.php',
            'target' => 'table:orders',
            'model' => 'Fixture\\Package\\PackageOrder',
            'reason' => 'An outer case can fall through into a nested switch in the next case.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Features/Billing/Actions/SwitchLabelLeakCharge.php',
            'target' => 'table:orders',
            'model' => 'Fixture\\Package\\PackageOrder',
            'reason' => 'An assignment in a nonmatching case label can reach a later label.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Features/Billing/Actions/SwitchThrowFlowCharge.php',
            'target' => 'table:orders',
            'model' => 'Fixture\\Package\\PackageOrder',
            'reason' => 'An expression throw terminates switch case fallthrough.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Features/Billing/Actions/SequentialIfLeakCharge.php',
            'target' => 'table:orders',
            'model' => 'Fixture\\Package\\PackageOrder',
            'reason' => 'A completed independent conditional may have reassigned the receiver.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Features/Billing/Actions/LoopThenIfLeakCharge.php',
            'target' => 'table:orders',
            'model' => 'Fixture\\Package\\PackageOrder',
            'reason' => 'A completed loop may have reassigned the receiver before the write.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Features/Billing/Actions/SwitchThenIfLeakCharge.php',
            'target' => 'table:orders',
            'model' => 'Fixture\\Package\\PackageOrder',
            'reason' => 'A completed switch may have reassigned the receiver before the write.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Features/Billing/Actions/DoConditionLeakCharge.php',
            'target' => 'table:orders',
            'model' => 'Fixture\\Package\\PackageOrder',
            'reason' => 'The do body assignment reaches its post-body condition.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Features/Billing/Actions/WhileConditionLeakCharge.php',
            'target' => 'table:orders',
            'model' => 'Fixture\\Package\\PackageOrder',
            'reason' => 'A while body assignment reaches the next condition evaluation.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Features/Billing/Actions/ForConditionLeakCharge.php',
            'target' => 'table:orders',
            'model' => 'Fixture\\Package\\PackageOrder',
            'reason' => 'A for body assignment reaches the next condition evaluation.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Features/Billing/Actions/ForUpdateLeakCharge.php',
            'target' => 'table:orders',
            'model' => 'Fixture\\Package\\PackageOrder',
            'reason' => 'A for body assignment reaches the update expression.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Features/Billing/Actions/ForeachRepeatBodyLeakCharge.php',
            'target' => 'table:orders',
            'model' => 'Fixture\\Package\\PackageOrder',
            'reason' => 'A later foreach-body assignment can reach the next iteration write.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Features/Billing/Actions/WhileRepeatBodyLeakCharge.php',
            'target' => 'table:orders',
            'model' => 'Fixture\\Package\\PackageOrder',
            'reason' => 'A later while-body assignment can reach the next iteration write.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Features/Billing/Actions/ForRepeatBodyLeakCharge.php',
            'target' => 'table:orders',
            'model' => 'Fixture\\Package\\PackageOrder',
            'reason' => 'A later for-body assignment can reach the next iteration write.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Features/Billing/Actions/DoRepeatBodyLeakCharge.php',
            'target' => 'table:orders',
            'model' => 'Fixture\\Package\\PackageOrder',
            'reason' => 'A later do-body assignment can reach the next iteration write.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Features/Billing/Actions/DoConditionNextBodyLeakCharge.php',
            'target' => 'table:orders',
            'model' => 'Fixture\\Package\\PackageOrder',
            'reason' => 'A do-condition assignment can reach the next body iteration.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Features/Billing/Actions/NestedLoopForUpdateLeakCharge.php',
            'target' => 'table:orders',
            'model' => 'Fixture\\Package\\PackageOrder',
            'reason' => 'An inner loop assignment can reach the enclosing for update.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Features/Billing/Actions/SiblingLoopIdentityCharge.php',
            'target' => 'table:orders',
            'model' => 'Fixture\\Package\\PackageOrder',
            'reason' => 'A later sibling loop cannot affect the completed earlier loop write.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
    ]);

    $result = $this->project->check();

    expect($result['exitCode'])->toBe(2)
        ->and($result['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/Charge.php table:orders')
        ->and($result['stderr'])->not->toContain('Remove stale exception: app/Features/Billing/Actions/AncestorCharge.php table:orders')
        ->and($result['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/BranchLeakCharge.php table:orders')
        ->and($result['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/ElseIfLeakCharge.php table:orders')
        ->and($result['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/JoinLeakCharge.php table:orders')
        ->and($result['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/ReferenceCaptureCharge.php table:orders')
        ->and($result['stderr'])->not->toContain('Remove stale exception: app/Features/Billing/Actions/SiblingFlowCharge.php table:orders')
        ->and($result['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/TryCatchLeakCharge.php table:orders')
        ->and($result['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/TryFinallyLeakCharge.php table:orders')
        ->and($result['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/CatchFinallyLeakCharge.php table:orders')
        ->and($result['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/CatchSiblingLeakCharge.php table:orders')
        ->and($result['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/SwitchFallthroughLeakCharge.php table:orders')
        ->and($result['stderr'])->not->toContain('Remove stale exception: app/Features/Billing/Actions/SwitchBreakFlowCharge.php table:orders')
        ->and($result['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/HeaderLeakCharge.php table:orders')
        ->and($result['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/TernaryLeakCharge.php table:orders')
        ->and($result['stderr'])->not->toContain('Remove stale exception: app/Features/Billing/Actions/TernarySameModelCharge.php table:orders')
        ->and($result['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/ElseIfConditionLeakCharge.php table:orders')
        ->and($result['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/SwitchNestedFallthroughLeakCharge.php table:orders')
        ->and($result['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/SwitchLabelLeakCharge.php table:orders')
        ->and($result['stderr'])->not->toContain('Remove stale exception: app/Features/Billing/Actions/SwitchThrowFlowCharge.php table:orders')
        ->and($result['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/SequentialIfLeakCharge.php table:orders')
        ->and($result['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/LoopThenIfLeakCharge.php table:orders')
        ->and($result['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/SwitchThenIfLeakCharge.php table:orders')
        ->and($result['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/DoConditionLeakCharge.php table:orders')
        ->and($result['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/WhileConditionLeakCharge.php table:orders')
        ->and($result['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/ForConditionLeakCharge.php table:orders')
        ->and($result['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/ForUpdateLeakCharge.php table:orders')
        ->and($result['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/ForeachRepeatBodyLeakCharge.php table:orders')
        ->and($result['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/WhileRepeatBodyLeakCharge.php table:orders')
        ->and($result['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/ForRepeatBodyLeakCharge.php table:orders')
        ->and($result['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/DoRepeatBodyLeakCharge.php table:orders')
        ->and($result['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/DoConditionNextBodyLeakCharge.php table:orders')
        ->and($result['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/NestedLoopForUpdateLeakCharge.php table:orders')
        ->and($result['stderr'])->not->toContain('Remove stale exception: app/Features/Billing/Actions/SiblingLoopIdentityCharge.php table:orders');
});

it('rejects a relation exception when the relation call is disconnected from the write', function (): void {
    $this->project->write('app/External/Bookmark.php', <<<'PHP'
<?php
namespace Fixture\Package;
class Bookmark extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'bookmarks';
}
PHP);
    $this->project->write('app/External/BookmarkOwner.php', <<<'PHP'
<?php
namespace Fixture\Relations;
class BookmarkOwner {}
PHP);
    $this->project->write('app/Features/Billing/Actions/Charge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class Charge
{
    public function handle(\Fixture\Relations\BookmarkOwner $event, object $profile): void
    {
        $event->savedBy();
        $profile->delete();
    }
}
PHP);
    $this->project->configure([[
        'rule' => 'architecture.writeLocation',
        'file' => 'app/Features/Billing/Actions/Charge.php',
        'target' => 'table:bookmarks',
        'model' => 'Fixture\\Package\\Bookmark',
        'relation' => 'savedBy',
        'relation_owner' => 'Fixture\\Relations\\BookmarkOwner',
        'reason' => 'The exact package relation owns this scoped write.',
        'owner' => 'Billing',
        'expires' => '2999-12-31',
    ]]);

    $result = $this->project->check();

    expect($result['exitCode'])->toBe(2)
        ->and($result['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/Charge.php table:bookmarks');
});

it('rejects a relation exception when a different owner exposes the same relation name', function (): void {
    $this->project->write('app/External/Bookmark.php', <<<'PHP'
<?php
namespace Fixture\Package;
class Bookmark extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'bookmarks';
}
PHP);
    $this->project->write('app/External/BookmarkOwner.php', <<<'PHP'
<?php
namespace Fixture\Relations;
class BookmarkOwner {}
PHP);
    $this->project->write('app/External/UnrelatedOwner.php', <<<'PHP'
<?php
namespace Fixture\Relations;
class UnrelatedOwner {}
PHP);
    $this->project->write('app/Features/Billing/Actions/Charge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class Charge
{
    public function handle(\Fixture\Relations\UnrelatedOwner $other): void
    {
        $other->savedBy()->delete();
    }
}
PHP);
    $this->project->configure([[
        'rule' => 'architecture.writeLocation',
        'file' => 'app/Features/Billing/Actions/Charge.php',
        'target' => 'table:bookmarks',
        'model' => 'Fixture\\Package\\Bookmark',
        'relation' => 'savedBy',
        'relation_owner' => 'Fixture\\Relations\\BookmarkOwner',
        'reason' => 'The exact package relation owns this scoped write.',
        'owner' => 'Billing',
        'expires' => '2999-12-31',
    ]]);

    $result = $this->project->check();

    expect($result['exitCode'])->toBe(2)
        ->and($result['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/Charge.php table:bookmarks');
});

it('rejects relation-owner return provenance borrowed from an unrelated receiver', function (): void {
    $this->project->write('app/External/Bookmark.php', <<<'PHP'
<?php
namespace Fixture\Package;
class Bookmark extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'bookmarks';
}
PHP);
    $this->project->write('app/External/BookmarkOwner.php', <<<'PHP'
<?php
namespace Fixture\Relations;
class BookmarkOwner {}
PHP);
    $this->project->write('app/External/UnrelatedReceiver.php', <<<'PHP'
<?php
namespace Fixture\Relations;
class UnrelatedReceiver
{
    public function relationOwner(): BookmarkOwner { return new BookmarkOwner; }
}
PHP);
    $this->project->write('app/Features/Billing/Actions/Charge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class Charge
{
    private function relationOwner(): \Fixture\Relations\BookmarkOwner { return new \Fixture\Relations\BookmarkOwner; }

    public function handle(\Fixture\Relations\UnrelatedReceiver $other): void
    {
        $other->relationOwner()->savedBy()->delete();
    }
}
PHP);
    $this->project->configure([[
        'rule' => 'architecture.writeLocation',
        'file' => 'app/Features/Billing/Actions/Charge.php',
        'target' => 'table:bookmarks',
        'model' => 'Fixture\\Package\\Bookmark',
        'relation' => 'savedBy',
        'relation_owner' => 'Fixture\\Relations\\BookmarkOwner',
        'reason' => 'The exact package relation owns this scoped write.',
        'owner' => 'Billing',
        'expires' => '2999-12-31',
    ]]);

    $result = $this->project->check();

    expect($result['exitCode'])->toBe(2)
        ->and($result['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/Charge.php table:bookmarks');
});

it('does not borrow local relation owner return provenance from another class in the same file', function (): void {
    $this->project->write('app/External/Bookmark.php', <<<'PHP'
<?php
namespace Fixture\Package;
class Bookmark extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'bookmarks';
}
PHP);
    $this->project->write('app/External/BookmarkOwner.php', <<<'PHP'
<?php
namespace Fixture\Relations;
class BookmarkOwner {}
PHP);
    $this->project->write('app/Features/Billing/Actions/Charge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class Charge
{
    public function handle(): void
    {
        $this->relationOwner()->savedBy()->delete();
    }
}
class Decoy
{
    private function relationOwner(): BookmarkOwner
    {
        return new BookmarkOwner;
    }
}
PHP);
    $this->project->configure([[
        'rule' => 'architecture.writeLocation',
        'file' => 'app/Features/Billing/Actions/Charge.php',
        'target' => 'table:bookmarks',
        'model' => 'Fixture\\Package\\Bookmark',
        'relation' => 'savedBy',
        'relation_owner' => 'Fixture\\Relations\\BookmarkOwner',
        'reason' => 'The exact package relation owns this scoped write.',
        'owner' => 'Billing',
        'expires' => '2999-12-31',
    ]]);

    $result = $this->project->check();

    expect($result['exitCode'])->toBe(2)
        ->and($result['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/Charge.php table:bookmarks');
});

it('does not borrow model or relation-owner property types from another class in the same file', function (): void {
    $this->project->write('app/External/PackageOrder.php', <<<'PHP'
<?php
namespace Fixture\Package;
class PackageOrder extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'orders';
}
class Bookmark extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'bookmarks';
}
PHP);
    $this->project->write('app/External/BookmarkOwner.php', <<<'PHP'
<?php
namespace Fixture\Relations;
class BookmarkOwner {}
PHP);
    $this->project->write('app/Features/Billing/Actions/Charge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class Charge
{
    public function handle(): void
    {
        $this->order->delete();
        $this->owner->savedBy()->delete();
    }
}
class Decoy
{
    public PackageOrder $order;
    public BookmarkOwner $owner;
}
PHP);
    $exception = [
        'rule' => 'architecture.foreignWrite',
        'file' => 'app/Features/Billing/Actions/Charge.php',
        'target' => 'table:orders',
        'model' => 'Fixture\\Package\\PackageOrder',
        'reason' => 'The exact package order model is written through this integration action.',
        'owner' => 'Billing',
        'expires' => '2999-12-31',
    ];
    $this->project->configure([$exception]);

    $modelResult = $this->project->check();

    expect($modelResult['exitCode'])->toBe(2)
        ->and($modelResult['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/Charge.php table:orders');

    $exception['rule'] = 'architecture.writeLocation';
    $exception['target'] = 'table:bookmarks';
    $exception['model'] = 'Fixture\\Package\\Bookmark';
    $exception['relation'] = 'savedBy';
    $exception['relation_owner'] = 'Fixture\\Relations\\BookmarkOwner';
    $exception['reason'] = 'The exact package relation owns this scoped write.';
    $this->project->configure([$exception]);

    $relationResult = $this->project->check();

    expect($relationResult['exitCode'])->toBe(2)
        ->and($relationResult['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/Charge.php table:bookmarks');
});

it('requires every property union arm to guarantee model or relation-owner methods', function (): void {
    $this->project->write('app/External/PackageOrder.php', <<<'PHP'
<?php
namespace Fixture\Package;
class PackageOrder extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'orders';
}
class Profile extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'profiles';
}
PHP);
    $this->project->write('app/External/Bookmark.php', <<<'PHP'
<?php
namespace Fixture\Package;
class Bookmark extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'bookmarks';
}
PHP);
    $this->project->write('app/External/BookmarkOwners.php', <<<'PHP'
<?php
namespace Fixture\Relations;
class BookmarkOwner {}
class UnrelatedOwner {}
PHP);
    $this->project->write('app/Platform/BookmarkOwnerAdapter.php', <<<'PHP'
<?php
namespace App\Platform;
class BookmarkOwnerAdapter
{
    public function relationOwner(): BookmarkOwner
    {
        return new BookmarkOwner;
    }
}
PHP);
    $this->project->write('app/Platform/UnrelatedOwnerAdapter.php', <<<'PHP'
<?php
namespace App\Platform;
class UnrelatedOwnerAdapter {}
PHP);
    $this->project->write('app/Platform/OrderProvider.php', <<<'PHP'
<?php
namespace App\Platform;
class OrderProvider implements OrderProviderMarker
{
    public function order(): PackageOrder
    {
        return new PackageOrder;
    }
}
PHP);
    $this->project->write('app/Platform/OrderProviderMarker.php', <<<'PHP'
<?php
namespace App\Platform;
interface OrderProviderMarker {}
PHP);
    $this->project->write('app/Platform/ProfileProvider.php', <<<'PHP'
<?php
namespace App\Platform;
class ProfileProvider
{
    public function order(): Profile
    {
        return new Profile;
    }
}
PHP);
    $this->project->write('app/Features/Billing/Actions/NullableExternalOwnerMethodCharge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class NullableExternalOwnerMethodCharge
{
    public ?BookmarkOwnerAdapter $owner;

    public function handle(): void
    {
        $this->owner->relationOwner()->savedBy()->delete();
    }
}
PHP);
    $this->project->write('app/Features/Billing/Actions/IntersectionExternalModelMethodCharge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class IntersectionExternalModelMethodCharge
{
    public OrderProvider&OrderProviderMarker $provider;

    public function handle(): void
    {
        $this->provider->order()->delete();
    }
}
PHP);
    $this->project->write('app/Features/Billing/Actions/DirectOwnerPropertyUnionCharge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class DirectOwnerPropertyUnionCharge
{
    public BookmarkOwner|UnrelatedOwner $owner;

    public function handle(): void
    {
        $this->owner->savedBy()->delete();
    }
}
PHP);
    $this->project->write('app/Features/Billing/Actions/ExternalOwnerMethodUnionCharge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class ExternalOwnerMethodUnionCharge
{
    public BookmarkOwnerAdapter|UnrelatedOwnerAdapter $owner;

    public function handle(): void
    {
        $this->owner->relationOwner()->savedBy()->delete();
    }
}
PHP);
    $this->project->write('app/Features/Billing/Actions/ExternalModelMethodUnionCharge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class ExternalModelMethodUnionCharge
{
    public OrderProvider|ProfileProvider $provider;

    public function handle(): void
    {
        $this->provider->order()->delete();
    }
}
PHP);
    $this->project->configure([
        [
            'rule' => 'architecture.writeLocation',
            'file' => 'app/Features/Billing/Actions/DirectOwnerPropertyUnionCharge.php',
            'target' => 'table:bookmarks',
            'model' => 'Fixture\\Package\\Bookmark',
            'relation' => 'savedBy',
            'relation_owner' => 'Fixture\\Relations\\BookmarkOwner',
            'reason' => 'A direct property must guarantee the configured relation owner.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
        [
            'rule' => 'architecture.writeLocation',
            'file' => 'app/Features/Billing/Actions/ExternalOwnerMethodUnionCharge.php',
            'target' => 'table:bookmarks',
            'model' => 'Fixture\\Package\\Bookmark',
            'relation' => 'savedBy',
            'relation_owner' => 'Fixture\\Relations\\BookmarkOwner',
            'reason' => 'Every provider property arm must return the configured relation owner.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Features/Billing/Actions/ExternalModelMethodUnionCharge.php',
            'target' => 'table:orders',
            'model' => 'Fixture\\Package\\PackageOrder',
            'reason' => 'Every provider property arm must return the configured model.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
        [
            'rule' => 'architecture.writeLocation',
            'file' => 'app/Features/Billing/Actions/NullableExternalOwnerMethodCharge.php',
            'target' => 'table:bookmarks',
            'model' => 'Fixture\\Package\\Bookmark',
            'relation' => 'savedBy',
            'relation_owner' => 'Fixture\\Relations\\BookmarkOwner',
            'reason' => 'A non-null nullable provider arm returns the configured relation owner.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Features/Billing/Actions/IntersectionExternalModelMethodCharge.php',
            'target' => 'table:orders',
            'model' => 'Fixture\\Package\\PackageOrder',
            'reason' => 'An intersection provider arm includes the exact model-returning method.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
    ]);

    $result = $this->project->check();

    expect($result['exitCode'])->toBe(2)
        ->and($result['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/DirectOwnerPropertyUnionCharge.php table:bookmarks')
        ->and($result['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/ExternalOwnerMethodUnionCharge.php table:bookmarks')
        ->and($result['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/ExternalModelMethodUnionCharge.php table:orders')
        ->and($result['stderr'])->not->toContain('Remove stale exception: app/Features/Billing/Actions/NullableExternalOwnerMethodCharge.php table:bookmarks')
        ->and($result['stderr'])->not->toContain('Remove stale exception: app/Features/Billing/Actions/IntersectionExternalModelMethodCharge.php table:orders');
});

it('resolves exact imported PHPDoc parameter owners and rejects mixed unions', function (): void {
    $this->project->write('app/External/Bookmark.php', <<<'PHP'
<?php
namespace Fixture\Package;
class Bookmark extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'bookmarks';
}
PHP);
    $this->project->write('app/External/PackageOwner.php', <<<'PHP'
<?php
namespace Fixture\Relations;
class PackageOwner {}
PHP);
    $this->project->write('app/External/BookmarkOwner.php', <<<'PHP'
<?php
namespace Fixture\Relations;
class BookmarkOwner extends PackageOwner {}
PHP);
    $this->project->write('app/External/UnrelatedOwner.php', <<<'PHP'
<?php
namespace Fixture\Relations;
class UnrelatedOwner extends PackageOwner {}
PHP);
    $this->project->write('app/Features/Billing/Actions/Charge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
use Fixture\Relations\BookmarkOwner as DeclaredOwner;
class Charge
{
    /** @param DeclaredOwner $owner */
    public function handle(PackageOwner $owner): void
    {
        $owner->savedBy()->delete();
    }
}
PHP);
    $this->project->configure([[
        'rule' => 'architecture.writeLocation',
        'file' => 'app/Features/Billing/Actions/Charge.php',
        'target' => 'table:bookmarks',
        'model' => 'Fixture\\Package\\Bookmark',
        'relation' => 'savedBy',
        'relation_owner' => 'Fixture\\Relations\\BookmarkOwner',
        'reason' => 'The exact package relation owns this scoped write.',
        'owner' => 'Billing',
        'expires' => '2999-12-31',
    ]]);

    $documentedOwner = $this->project->check();

    expect($documentedOwner)->toMatchArray(['exitCode' => 0, 'stderr' => '']);

    $this->project->write('app/Features/Billing/Actions/Charge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
use Fixture\Relations\BookmarkOwner as ExactOwner;
class Charge
{
    /** @param ExactOwner|UnrelatedOwner $owner */
    public function handle(PackageOwner $owner): void
    {
        $owner->savedBy()->delete();
    }
}
PHP);

    $unrelatedDocblock = $this->project->check();

    expect($unrelatedDocblock['exitCode'])->toBe(2)
        ->and($unrelatedDocblock['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/Charge.php table:bookmarks');
});

it('requires native union types to guarantee the exact model or relation owner', function (): void {
    $this->project->write('app/External/OwnedModels.php', <<<'PHP'
<?php
namespace Fixture\Package;
interface OrderContract {}
class PackageOrder extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'orders';
}
class Profile extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'profiles';
}
class IntersectionOrder extends \Illuminate\Database\Eloquent\Model implements OrderContract
{
    protected $table = 'orders';
}
class Bookmark extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'bookmarks';
}
PHP);
    $this->project->write('app/External/BookmarkOwners.php', <<<'PHP'
<?php
namespace Fixture\Relations;
interface OwnerContract {}
class BookmarkOwner implements OwnerContract {}
class UnrelatedOwner implements OwnerContract {}
PHP);
    $this->project->write('app/Features/Billing/Actions/NullableModelCharge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class NullableModelCharge
{
    public function handle(?PackageOrder $order): void
    {
        $order?->delete();
    }
}
PHP);
    $this->project->write('app/Features/Billing/Actions/IntersectionModelCharge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class IntersectionModelCharge
{
    public function handle(IntersectionOrder&OrderContract $order): void
    {
        $order->delete();
    }
}
PHP);
    $this->project->write('app/Features/Billing/Actions/ModelUnionCharge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class ModelUnionCharge
{
    public function handle(PackageOrder|Profile $order): void
    {
        $order->delete();
    }
}
PHP);
    $this->project->write('app/Features/Billing/Actions/NullableOwnerCharge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class NullableOwnerCharge
{
    public function handle(?BookmarkOwner $owner): void
    {
        $owner?->savedBy()?->delete();
    }
}
PHP);
    $this->project->write('app/Features/Billing/Actions/IntersectionOwnerCharge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class IntersectionOwnerCharge
{
    public function handle(BookmarkOwner&OwnerContract $owner): void
    {
        $owner->savedBy()->delete();
    }
}
PHP);
    $this->project->write('app/Features/Billing/Actions/OwnerUnionCharge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class OwnerUnionCharge
{
    public function handle(BookmarkOwner|UnrelatedOwner $owner): void
    {
        $owner->savedBy()->delete();
    }
}
PHP);

    $this->project->configure([
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Features/Billing/Actions/NullableModelCharge.php',
            'target' => 'table:orders',
            'model' => 'Fixture\\Package\\PackageOrder',
            'reason' => 'The nullable receiver is still the exact package order model.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Features/Billing/Actions/IntersectionModelCharge.php',
            'target' => 'table:orders',
            'model' => 'Fixture\\Package\\IntersectionOrder',
            'reason' => 'The intersection receiver includes the exact package order model.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
        [
            'rule' => 'architecture.foreignWrite',
            'file' => 'app/Features/Billing/Actions/ModelUnionCharge.php',
            'target' => 'table:orders',
            'model' => 'Fixture\\Package\\PackageOrder',
            'reason' => 'Every union arm must identify the exact package order model.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
        [
            'rule' => 'architecture.writeLocation',
            'file' => 'app/Features/Billing/Actions/NullableOwnerCharge.php',
            'target' => 'table:bookmarks',
            'model' => 'Fixture\\Package\\Bookmark',
            'relation' => 'savedBy',
            'relation_owner' => 'Fixture\\Relations\\BookmarkOwner',
            'reason' => 'The nullable owner is still the exact bookmark relation owner.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
        [
            'rule' => 'architecture.writeLocation',
            'file' => 'app/Features/Billing/Actions/IntersectionOwnerCharge.php',
            'target' => 'table:bookmarks',
            'model' => 'Fixture\\Package\\Bookmark',
            'relation' => 'savedBy',
            'relation_owner' => 'Fixture\\Relations\\BookmarkOwner',
            'reason' => 'The intersection owner includes the exact bookmark relation owner.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
        [
            'rule' => 'architecture.writeLocation',
            'file' => 'app/Features/Billing/Actions/OwnerUnionCharge.php',
            'target' => 'table:bookmarks',
            'model' => 'Fixture\\Package\\Bookmark',
            'relation' => 'savedBy',
            'relation_owner' => 'Fixture\\Relations\\BookmarkOwner',
            'reason' => 'Every union arm must identify the exact bookmark relation owner.',
            'owner' => 'Billing',
            'expires' => '2999-12-31',
        ],
    ]);

    $result = $this->project->check();

    expect($result['exitCode'])->toBe(2)
        ->and($result['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/ModelUnionCharge.php table:orders')
        ->and($result['stderr'])->toContain('Remove stale exception: app/Features/Billing/Actions/OwnerUnionCharge.php table:bookmarks')
        ->and($result['stderr'])->not->toContain('Remove stale exception: app/Features/Billing/Actions/NullableModelCharge.php table:orders')
        ->and($result['stderr'])->not->toContain('Remove stale exception: app/Features/Billing/Actions/IntersectionModelCharge.php table:orders')
        ->and($result['stderr'])->not->toContain('Remove stale exception: app/Features/Billing/Actions/NullableOwnerCharge.php table:bookmarks')
        ->and($result['stderr'])->not->toContain('Remove stale exception: app/Features/Billing/Actions/IntersectionOwnerCharge.php table:bookmarks');
});

it('rejects owned-write exceptions when the write is gone or targets another resource', function (): void {
    $this->project->writer('Billing', 'Charge');
    $this->project->configure([[
        'rule' => 'architecture.foreignWrite',
        'file' => 'app/Features/Billing/Actions/Charge.php',
        'target' => 'table:orders',
        'model' => 'App\\Features\\Orders\\Models\\Order',
        'reason' => 'Stale cross-feature write.',
        'owner' => 'Billing',
        'expires' => '2999-12-31',
    ]]);

    $result = $this->project->check();

    expect($result['exitCode'])->toBe(2)
        ->and($result['stderr'])->toContain('Remove stale exception');
});

it('rejects an unknown-table exception when its declared model is not used by the exact file', function (): void {
    $this->project->feature('Billing');
    $this->project->write('app/External/UnmappedOrder.php', <<<'PHP'
<?php
namespace Fixture\Unmapped;
class UnmappedOrder extends \Illuminate\Database\Eloquent\Model {}
PHP);
    $this->project->write('app/Features/Billing/Actions/Charge.php', <<<'PHP'
<?php
namespace App\Features\Billing\Actions;
class Charge
{
    public function handle(UnmappedOrder $order): void { $order->save(); }
}
PHP);
    $this->project->configure([[
        'rule' => 'architecture.writeLocation',
        'file' => 'app/Features/Billing/Actions/Charge.php',
        'target' => 'table:unknown',
        'model' => 'Fixture\\Unmapped\\UnmappedRefund',
        'reason' => 'The exact package-owned refund model remains external.',
        'owner' => 'Billing',
        'expires' => '2999-12-31',
    ]]);

    $result = $this->project->check();

    expect($result['exitCode'])->toBe(2)
        ->and($result['stderr'])->toContain('Remove stale exception');
});

it('rejects an expired owned-write exception', function (): void {
    $this->project->configure([[
        'rule' => 'architecture.writeLocation',
        'file' => 'app/Features/Billing/Actions/Charge.php',
        'target' => 'table:orders',
        'model' => 'Fixture\\Unmapped\\UnmappedRefund',
        'reason' => 'Expired root adapter.',
        'owner' => 'Billing',
        'expires' => '2000-01-01',
    ]]);

    $result = $this->project->check();

    expect($result['exitCode'])->toBe(2)
        ->and($result['stderr'])->toContain('Invalid/expired exception');
});
