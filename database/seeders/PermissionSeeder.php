<?php

declare(strict_types=1);

namespace Database\Seeders;

use AIArmada\Authz\Models\Permission;
use AIArmada\Authz\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Spatie resolves findOrCreate() from the cached permission collection
        // (Redis), which survives `migrate:fresh`. Without flushing first, a
        // stale cache makes every lookup hit and skips the DB inserts, so the
        // later givePermissionTo() fails on an empty table.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'feedback.blocked',
            'institution.view',
            'institution.update',
            'institution.delete',
            'institution.manage-members',
            'institution.manage-donation-channels',
            'person.viewAny',
            'person.view',
            'person.create',
            'person.update',
            'person.delete',
            'person.manage-members',
            'event.view',
            'event.create',
            'event.update',
            'event.delete',
            'event.manage-members',
            'event.view-registrations',
            'event.manage-attendance',
            'event.manage-admissions',
            'event.export-registrations',
            'affiliate.viewAny',
            'affiliate.view',
            'affiliate.update',
            'affiliate.approve',
            'affiliate_conversion.viewAny',
            'affiliate_conversion.view',
            'affiliate_conversion.update',
            'affiliates.fraud.view-any',
            'affiliates.fraud.view',
            'affiliates.fraud.update',
            'affiliates.payout.view-any',
            'affiliates.payout.view',
            'affiliates.payout.update',
            'affiliates.commission.update',
            'affiliate.analytics',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // findOrCreate populates Spatie's in-memory permission collection on
        // first read and never refreshes it, so newly created rows are
        // invisible to givePermissionTo() in the same process (notably on a
        // fresh database). Bust the cache before assigning.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::findOrCreate('admin', 'web')->givePermissionTo([
            'person.viewAny',
            'person.view',
            'person.create',
            'person.update',
            'person.delete',
            'person.manage-members',
            'affiliate.viewAny',
            'affiliate.view',
            'affiliate.update',
            'affiliate.approve',
            'affiliate_conversion.viewAny',
            'affiliate_conversion.view',
            'affiliate_conversion.update',
            'affiliates.fraud.view-any',
            'affiliates.fraud.view',
            'affiliates.fraud.update',
            'affiliates.payout.view-any',
            'affiliates.payout.view',
            'affiliates.payout.update',
            'affiliates.commission.update',
            'affiliate.analytics',
        ]);
    }
}
