<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\Authz\ScopedMemberRoleSeeder;
use Illuminate\Database\Seeder;

class ScopedMemberRolesSeeder extends Seeder
{
    /**
     * Seed shared member role templates for institution, speaker, event, and reference membership scopes.
     */
    public function run(): void
    {
        /** @var ScopedMemberRoleSeeder $scopedRoleSeeder */
        $scopedRoleSeeder = app(ScopedMemberRoleSeeder::class);

        $scopedRoleSeeder->ensureForInstitution();
        $scopedRoleSeeder->ensureForSpeaker();
        $scopedRoleSeeder->ensureForEvent();
        $scopedRoleSeeder->ensureForReference();
    }
}
