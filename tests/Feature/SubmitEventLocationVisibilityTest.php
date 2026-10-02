<?php

use App\Enums\EventFormat;
use App\Livewire\Pages\SubmitEvent\Create;
use Database\Seeders\AIArmada\EventRoleSeeder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(EventRoleSeeder::class);
});

test('event format can be set to physical', function () {
    Livewire::test(Create::class)
        ->set('data.event_format', EventFormat::Physical->value)
        ->assertSet('data.event_format', EventFormat::Physical->value);
});

test('event format can be set to online', function () {
    Livewire::test(Create::class)
        ->set('data.event_format', EventFormat::Online->value)
        ->assertSet('data.event_format', EventFormat::Online->value);
});

test('event format can be set to hybrid', function () {
    Livewire::test(Create::class)
        ->set('data.event_format', EventFormat::Hybrid->value)
        ->assertSet('data.event_format', EventFormat::Hybrid->value);
});

test('live url is not required on the public submit-event form', function () {
    Livewire::test(Create::class)
        ->assertFormFieldExists('live_url', function (TextInput $input): bool {
            expect($input->isRequired())->toBeFalse();

            return true;
        });
});

test('community event type forces physical format', function () {
    Livewire::test(Create::class)
        ->set('data.event_format', EventFormat::Online->value)
        ->set('data.event_category_ids', [eventCategoryId('komuniti_kebajikan')])
        ->assertSet('data.event_format', EventFormat::Physical->value);
});

test('non-community event type does not force physical format', function () {
    Livewire::test(Create::class)
        ->set('data.event_format', EventFormat::Online->value)
        ->set('data.event_category_ids', [eventCategoryId('kuliah_ceramah')])
        ->assertSet('data.event_format', EventFormat::Online->value);
});

test('online events do not require hidden physical location selections', function (string $locationType, string $fieldName): void {
    Livewire::test(Create::class)
        ->set('data.primary_organizer_kind', 'person')
        ->set('data.location_type', $locationType)
        ->set('data.event_format', EventFormat::Online->value)
        ->assertFormFieldExists($fieldName, function (Select $field): bool {
            expect($field->isRequired())->toBeFalse();

            return true;
        });
})->with([
    'institution' => ['institution', 'location_institution_id'],
    'venue' => ['venue', 'location_venue_id'],
]);
