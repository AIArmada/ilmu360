<?php

use App\Actions\ReorderFeaturedPersonsAction;
use App\Filament\Resources\Persons\Pages\EditPerson;
use App\Filament\Resources\Persons\Pages\ListPersons;
use App\Filament\Resources\Persons\PersonResource;
use App\Models\Person;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
});

it('lets a global administrator feature a person from the edit page', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $person = Person::factory()->create(['name' => 'Penceramah Baharu']);

    Livewire::actingAs($administrator)
        ->test(EditPerson::class, ['record' => $person->id])
        ->assertSee('Featured on homepage')
        ->assertSet('data.is_featured', false)
        ->set('data.is_featured', true)
        ->call('save')
        ->assertHasNoErrors();

    $person->refresh();

    expect($person->is_featured)->toBeTrue()
        ->and($person->order_column)->not->toBeNull();
});

it('prevents users without person update permission from editing the selection', function () {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo('person.viewAny');

    $person = Person::factory()->create();

    $this->actingAs($viewer)
        ->get(PersonResource::getUrl('edit', ['record' => $person], panel: 'admin'))
        ->assertForbidden();
});

it('exposes featured controls and ordering on the persons table', function () {
    $table = PersonResource::table(Table::make(Mockery::mock(HasTable::class)));

    expect($table->getColumn('is_featured'))->not->toBeNull()
        ->and($table->getFilter('is_featured'))->not->toBeNull()
        ->and($table->getReorderColumn())->toBe('order_column');
});

it('reorders featured people for administrators only', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $first = Person::factory()->create(['is_featured' => true, 'order_column' => 1]);
    $second = Person::factory()->create(['is_featured' => true, 'order_column' => 2]);

    Livewire::actingAs($administrator)
        ->test(ListPersons::class)
        ->call('reorderTable', [$second->id, $first->id]);

    expect($first->fresh()->order_column)->toBe(2)
        ->and($second->fresh()->order_column)->toBe(1);

    $viewer = User::factory()->create();
    $viewer->givePermissionTo('person.viewAny');

    Livewire::actingAs($viewer)
        ->test(ListPersons::class)
        ->call('reorderTable', [$first->id, $second->id]);

    expect($first->fresh()->order_column)->toBe(2)
        ->and($second->fresh()->order_column)->toBe(1);
});

it('rejects homepage curation by non administrators even through direct writes', function () {
    $person = Person::factory()->create();
    $member = User::factory()->create();
    $this->actingAs($member);

    expect(fn () => $person->update(['is_featured' => true]))
        ->toThrow(AuthorizationException::class);
    expect($person->fresh()->is_featured)->toBeFalse();

    expect(Person::factory()->create()->is_featured)->toBeFalse();
});

it('preserves hidden featured positions and audits filtered reordering', function () {
    config(['audit.console' => true]);
    $people = collect(range(1, 4))->map(fn (int $position) => Person::factory()->create([
        'is_featured' => true, 'order_column' => $position,
    ]));
    $administrator = User::factory()->create();
    $administrator->assignRole('admin');
    $this->actingAs($administrator);

    app(ReorderFeaturedPersonsAction::class)->handle([$people[3]->id, $people[2]->id]);

    $expected = [$people[0]->id, $people[1]->id, $people[3]->id, $people[2]->id];
    expect(Person::query()->featured()->ordered()->pluck('id')->all())->toBe($expected);
    $audit = $people[0]->audits()->where('event', 'featured_reordered')->firstOrFail();
    expect($audit->old_values['featured_person_ids'])->toBe($people->pluck('id')->all())
        ->and($audit->new_values['featured_person_ids'])->toBe($expected)
        ->and($audit->user_id)->toBe($administrator->id);
});

it('rejects duplicate and unfeatured reorder submissions without changing positions', function () {
    $person = Person::factory()->create(['is_featured' => true, 'order_column' => 10]);
    $plain = Person::factory()->create();
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');
    $this->actingAs($administrator);

    foreach ([[$person->id, $person->id], [$plain->id]] as $order) {
        expect(fn () => app(ReorderFeaturedPersonsAction::class)->handle($order))
            ->toThrow(ValidationException::class);
    }
    expect($person->fresh()->order_column)->toBe(10);
});
