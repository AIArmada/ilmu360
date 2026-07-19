<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class ProductionSeeder extends Seeder
{
    /**
     * Seed only deterministic bootstrap data that is safe for production.
     */
    public function run(): void
    {
        $this->call([WorldSeeder::class]);
        $this->call([MalaysiaCitySeeder::class]);
        $this->call([DistrictSeeder::class]);
        $this->call([SubdistrictSeeder::class]);
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            ScopedMemberRolesSeeder::class,
            UserSeeder::class,
            FacilityTypeSeeder::class,
            SpaceSeeder::class,
            InspirationSeeder::class,
        ]);
    }
}
