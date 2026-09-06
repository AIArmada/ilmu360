<?php

use App\Filament\Resources\Reports\Pages\ListReports;
use App\Models\Report;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(PermissionSeeder::class);
});

it('denies bulk report deletion to moderators and preserves the reports', function (): void {
    $moderator = User::factory()->create();
    $moderator->assignRole('moderator');
    $report = Report::factory()->create(['status' => Report::STATUS_OPEN]);

    Livewire::actingAs($moderator)
        ->test(ListReports::class)
        ->assertCanSeeTableRecords([$report])
        ->assertTableBulkActionHidden('delete');

    $this->assertModelExists($report);
});

it('allows super admins to bulk delete reports', function (): void {
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');
    $report = Report::factory()->create(['status' => Report::STATUS_OPEN]);

    Livewire::actingAs($administrator)
        ->test(ListReports::class)
        ->assertCanSeeTableRecords([$report])
        ->callTableBulkAction('delete', [$report])
        ->assertNotified();

    $this->assertModelMissing($report);
});
