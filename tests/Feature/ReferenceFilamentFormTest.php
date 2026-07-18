<?php

use App\Enums\ReferencePartType;
use App\Enums\ReferenceType;
use App\Filament\Resources\References\Pages\CreateReference;
use App\Models\Reference;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;

it('persists a selected parent book through the direct Filament reference form', function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $parent = Reference::factory()->create([
        'title' => 'Root Book',
        'type' => ReferenceType::Book->value,
        'parent_id' => null,
    ]);

    Livewire::actingAs($administrator)
        ->test(CreateReference::class)
        ->fillForm([
            'title' => 'Root Book Volume Two',
            'type' => ReferenceType::Book->value,
            'parent_id' => $parent->id,
            'part_type' => ReferencePartType::Jilid->value,
            'part_number' => '2',
            'year' => '2024',
            'status' => 'verified',
            'socialProfiles' => [],
        ])
        ->call('create')
        ->assertHasNoErrors();

    $part = Reference::query()
        ->where('title', 'Root Book Volume Two')
        ->firstOrFail();

    expect($part->parent_id)->toBe($parent->id)
        ->and($part->part_type)->toBe(ReferencePartType::Jilid->value)
        ->and($part->part_number)->toBe(2)
        ->and($part->year)->toBe(2024);
});
