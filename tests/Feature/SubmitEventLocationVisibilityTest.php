<?php

use App\Enums\EventFormat;
use App\Enums\EventType;
use Filament\Forms\Components\TextInput;
use Livewire\Livewire;

test('event format can be set to physical', function () {
    Livewire::test('pages.submit-event.create')
        ->set('data.event_format', EventFormat::Physical->value)
        ->assertSet('data.event_format', EventFormat::Physical->value);
});

test('event format can be set to online', function () {
    Livewire::test('pages.submit-event.create')
        ->set('data.event_format', EventFormat::Online->value)
        ->assertSet('data.event_format', EventFormat::Online->value);
});

test('event format can be set to hybrid', function () {
    Livewire::test('pages.submit-event.create')
        ->set('data.event_format', EventFormat::Hybrid->value)
        ->assertSet('data.event_format', EventFormat::Hybrid->value);
});

test('live url is not required on the public submit-event form', function () {
    Livewire::test('pages.submit-event.create')
        ->assertFormFieldExists('live_url', function (TextInput $input): bool {
            expect($input->isRequired())->toBeFalse();

            return true;
        });
});

test('community event type forces physical format', function () {
    Livewire::test('pages.submit-event.create')
        ->set('data.event_format', EventFormat::Online->value)
        ->set('data.event_type', [EventType::Iftar->value])
        ->assertSet('data.event_format', EventFormat::Physical->value);
});

test('non-community event type does not force physical format', function () {
    Livewire::test('pages.submit-event.create')
        ->set('data.event_format', EventFormat::Online->value)
        ->set('data.event_type', [EventType::KuliahCeramah->value])
        ->assertSet('data.event_format', EventFormat::Online->value);
});
