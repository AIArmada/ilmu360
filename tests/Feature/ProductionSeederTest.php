<?php

use AIArmada\Addressing\Actions\SeedAddressCitiesAction;
use App\Models\Space;
use Database\Seeders\AddressingSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DistrictSeeder;
use Database\Seeders\FacilityTypeSeeder;
use Database\Seeders\InspirationSeeder;
use Database\Seeders\MalaysiaCitySeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ProductionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\ScopedMemberRolesSeeder;
use Database\Seeders\SpaceSeeder;
use Database\Seeders\SubdistrictSeeder;
use Database\Seeders\UserSeeder;
use Database\Seeders\WorldSeeder;
use Illuminate\Support\Arr;

it('delegates default seeding to the production seeder in production', function () {
    $originalEnvironment = app()->environment();
    app()['env'] = 'production';

    try {
        $seeder = new class extends DatabaseSeeder
        {
            /**
             * @var array<int, class-string>
             */
            public array $calledSeeders = [];

            /**
             * @param  array<class-string>|class-string  $class
             */
            public function call($class, $silent = false, array $parameters = []): static
            {
                $this->calledSeeders = array_merge($this->calledSeeders, Arr::wrap($class));

                return $this;
            }
        };

        $seeder->run();

        expect($seeder->calledSeeders)->toBe([ProductionSeeder::class]);
    } finally {
        app()['env'] = $originalEnvironment;
    }
});

it('production seeder only calls deterministic bootstrap seeders', function () {
    $seeder = new class extends ProductionSeeder
    {
        /**
         * @var array<int, class-string>
         */
        public array $calledSeeders = [];

        /**
         * @param  array<class-string>|class-string  $class
         */
        public function call($class, $silent = false, array $parameters = []): static
        {
            $this->calledSeeders = array_merge($this->calledSeeders, Arr::wrap($class));

            return $this;
        }
    };

    $seeder->run();

    expect($seeder->calledSeeders)->toBe([
        WorldSeeder::class,
        MalaysiaCitySeeder::class,
        DistrictSeeder::class,
        SubdistrictSeeder::class,
        PermissionSeeder::class,
        RoleSeeder::class,
        ScopedMemberRolesSeeder::class,
        UserSeeder::class,
        FacilityTypeSeeder::class,
        SpaceSeeder::class,
        InspirationSeeder::class,
    ]);
});

it('reduces city seed data outside production while keeping production complete', function () {
    $method = new ReflectionMethod(AddressingSeeder::class, 'citySeedRows');
    $seeder = new AddressingSeeder;
    $cities = app(SeedAddressCitiesAction::class);
    $originalEnvironment = app()->environment();

    try {
        app()['env'] = 'testing';
        $sample = $method->invoke($seeder, $cities);

        $actionPath = new ReflectionClass($cities)->getFileName();

        expect($actionPath)->toBeString();

        $allCities = json_decode(
            file_get_contents(dirname((string) $actionPath, 3).'/resources/data/cities.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        expect($sample)->toBeArray()->not->toBeEmpty()
            ->and(collect($sample)->where('country_code', 'MY')->count())
            ->toBe(collect($allCities)->where('country_code', 'MY')->count());

        app()['env'] = 'production';

        expect($method->invoke($seeder, $cities))->toBeNull();
    } finally {
        app()['env'] = $originalEnvironment;
    }
});

it('seeds common spaces deterministically without factories', function () {
    (new SpaceSeeder)->run();

    expect(Space::query()->count())->toBe(20)
        ->and(Space::query()->where('name', 'Dewan Utama')->first())
        ->not->toBeNull()
        ->and(Space::query()->where('name', 'Dewan Utama')->value('slug'))
        ->toBe('dewan-utama')
        ->and(Space::query()->where('name', 'Dewan Utama')->value('status'))
        ->toBe('active');
});
