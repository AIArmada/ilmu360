<?php

declare(strict_types=1);

namespace Database\Seeders;

use AIArmada\Authz\Models\Permission;
use AIArmada\Authz\Models\Role;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = [
            'feedback.blocked',
            'institution.view',
            'institution.update',
            'institution.delete',
            'institution.manage-members',
            'institution.manage-donation-channels',
            'person.view',
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

        Role::findOrCreate('admin', 'web')->givePermissionTo([
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
