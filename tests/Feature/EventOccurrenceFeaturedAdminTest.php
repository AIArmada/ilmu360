<?php

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\FilamentEvents\Resources\EventOccurrenceResource;
use App\Filament\Resources\EventOccurrences\EventOccurrenceFeaturedFormExtension;
use App\Models\Event;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);
});

it('exposes the featured occurrence control to global administrators', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $event = Event::factory()->create();
    $occurrence = $event->occurrences()->firstOrFail();

    $this->actingAs($administrator)
        ->get(EventOccurrenceResource::getUrl('edit', ['record' => $occurrence], panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('Feature this occurrence')
        ->assertSee('Featured upcoming public occurrences appear on the homepage; past occurrences do not.');

    OwnerContext::withOwner(null, fn () => $occurrence->update(['is_featured' => true]));

    expect($occurrence->fresh()?->is_featured)->toBeTrue();
});

it('warns administrators when a featured occurrence is already in the past', function () {
    $administrator = User::factory()->create();
    $administrator->assignRole('super_admin');

    $event = Event::factory()->create([
        'starts_at' => now()->subDays(2),
        'ends_at' => now()->subDay(),
    ]);
    $occurrence = $event->occurrences()->firstOrFail();

    $this->actingAs($administrator)
        ->get(EventOccurrenceResource::getUrl('edit', ['record' => $occurrence], panel: 'admin'))
        ->assertSuccessful()
        ->assertSee('This occurrence is in the past and will not appear on the homepage.');
});

it('hides the featured occurrence control from non administrators', function () {
    $this->actingAs(User::factory()->create());

    expect(app(EventOccurrenceFeaturedFormExtension::class)->components())->toBe([]);
});

it('prevents non administrators from curating featured occurrences through direct writes', function () {
    $event = Event::factory()->create();
    $occurrence = $event->occurrences()->firstOrFail();
    $member = User::factory()->create();
    $this->actingAs($member);

    expect(fn () => $occurrence->update(['is_featured' => true]))
        ->toThrow(AuthorizationException::class);

    expect($occurrence->fresh()?->is_featured)->toBeFalse();
});

it('exposes featured controls on the occurrence table', function () {
    $table = EventOccurrenceResource::table(Table::make(Mockery::mock(HasTable::class)));

    expect($table->getColumn('is_featured'))->not->toBeNull()
        ->and($table->getFilter('is_featured'))->not->toBeNull();
});
