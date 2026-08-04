<?php

declare(strict_types=1);

namespace Database\Seeders;

use AIArmada\Addressing\Database\Seeders\MalaysiaPostalCodeSeeder;
use Illuminate\Database\Seeder;

class ProductionSeeder extends Seeder
{
    /**
     * Seed only deterministic bootstrap data that is safe for production.
     */
    public function run(): void
    {
        $this->call([AddressingSeeder::class]);
        $this->call([MalaysiaPostalCodeSeeder::class]);
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            ScopedMemberRolesSeeder::class,
            UserSeeder::class,
            FacilityTypeSeeder::class,
            VenueSpaceTypeSeeder::class,
            SpaceSeeder::class,
            InspirationSeeder::class,
        ]);
    }
}
