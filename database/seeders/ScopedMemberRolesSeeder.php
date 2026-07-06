<?php

declare(strict_types=1);

namespace Database\Seeders;

use AIArmada\Membership\Services\MembershipRoleSyncService;
use Illuminate\Database\Seeder;

class ScopedMemberRolesSeeder extends Seeder
{
    public function run(): void
    {
        app(MembershipRoleSyncService::class)->syncAll();
    }
}
