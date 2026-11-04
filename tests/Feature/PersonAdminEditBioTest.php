<?php

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Filament\Resources\Persons\Pages\EditPerson;
use App\Filament\Resources\Persons\PersonResource;
use App\Models\Person;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;

function createAdminPersonEditor(): User
{
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    return $administrator;
}

it('renders the edit page for a person with a legacy locale-map biography', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = createAdminPersonEditor();

    $person = Person::factory()->create([
        'bio' => [
            'ms' => "Al-Fadhil Ustaz contoh berasal dari Slim River.\n\nBeliau aktif menyampaikan ceramah.",
        ],
    ]);

    OwnerContext::withOwner(null, function () use ($administrator, $person): void {
        $this->actingAs($administrator)
            ->get(PersonResource::getUrl('edit', ['record' => $person]))
            ->assertSuccessful();
    });
});

it('hydrates a legacy locale-map biography into the rich editor', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    $administrator = createAdminPersonEditor();

    $person = Person::factory()->create([
        'bio' => ['ms' => 'Al-Fadhil Ustaz contoh berasal dari Slim River.'],
    ]);

    OwnerContext::withOwner(null, function () use ($administrator, $person): void {
        Livewire::actingAs($administrator)
            ->test(EditPerson::class, ['record' => $person->id])
            ->assertSuccessful()
            ->assertSet('data.bio.type', 'doc')
            ->assertSet('data.bio.content.0.content.0.text', 'Al-Fadhil Ustaz contoh berasal dari Slim River.');
    });
});
