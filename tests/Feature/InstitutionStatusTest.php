<?php

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Filament\Resources\Institutions\Pages\EditInstitution;
use App\Models\Institution;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('exposes only supported institution lifecycle statuses in the admin form', function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');
    $institution = Institution::factory()->create(['status' => 'pending']);

    OwnerContext::withOwner(null, function () use ($administrator, $institution): void {
        Livewire::actingAs($administrator)
            ->test(EditInstitution::class, ['record' => $institution->getKey()])
            ->assertFormFieldExists('status', function (Select $field): bool {
                expect($field->getOptions())->toBe([
                    'pending' => 'Pending',
                    'verified' => 'Verified',
                    'rejected' => 'Rejected',
                    'inactive' => 'Inactive',
                ]);

                return true;
            });
    });
});
