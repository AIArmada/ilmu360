<?php

use App\Enums\EventFormat;
use App\Livewire\Pages\SubmitEvent\Create;
use App\Models\Space;
use App\Models\Venue;
use Database\Seeders\AIArmada\EventRoleSeeder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Livewire\Livewire;
use Ysfkaya\FilamentPhoneInput\Forms\PhoneInput;
use Ysfkaya\FilamentPhoneInput\PhoneInputNumberType;

beforeEach(function () {
    $this->seed(EventRoleSeeder::class);
});

it('refreshes institution space options while keeping other organizer synchronization in the browser', function (): void {
    Livewire::test(Create::class)
        ->assertFormFieldExists('primary_organizer_kind', function (Radio $field): bool {
            expect($field->isLive())->toBeFalse()
                ->and(implode("\n", $field->getAfterStateUpdatedJs()))
                ->toContain('primary_organizer_institution_id', 'primary_organizer_person_id');

            return true;
        })
        ->assertFormFieldExists('primary_organizer_institution_id', function (Select $field): bool {
            expect($field->isLive())->toBeTrue()
                ->and(implode("\n", $field->getAfterStateUpdatedJs()))
                ->toContain('primary_organizer_id', 'location_institution_id', 'location_venue_id');

            return true;
        })
        ->assertFormFieldExists('primary_organizer_person_id', function (Select $field): bool {
            expect($field->isLive())->toBeFalse()
                ->and(implode("\n", $field->getAfterStateUpdatedJs()))
                ->toContain('primary_organizer_id', 'currentPersons');

            return true;
        });
});
it('keeps repeater person linking and guest contact requirements client-side', function (): void {
    Livewire::test(Create::class)
        ->assertFormFieldExists('other_key_people', function (Repeater $field): bool {
            $involveableField = collect($field->getChildComponents())
                ->first(fn (mixed $component): bool => $component instanceof Select && $component->getName() === 'involveable_id');

            expect($involveableField)
                ->toBeInstanceOf(Select::class)
                ->and($involveableField?->isLive())->toBeFalse()
                ->and(implode("\n", $involveableField?->getAfterStateUpdatedJs() ?? []))
                ->toContain('display_name', 'involveable_type');

            return true;
        })
        ->assertFormFieldExists('submitter_email', function (TextInput $field): bool {
            expect($field->isLive())->toBeFalse()
                ->and($field->getExtraAlpineAttributes())
                ->toHaveKey('x-bind:required');

            return true;
        })
        ->assertFormFieldExists('submitter_phone', function (PhoneInput $field): bool {
            // PhoneInput extends the base Field, which has no
            // extraAlpineAttributes support; the conditional
            // required() closure below remains the enforcement.
            expect($field->isLive())->toBeFalse()
                ->and($field->getInitialCountry())->toBe('MY')
                ->and($field->getDisplayNumberFormat())->toBe(PhoneInputNumberType::INTERNATIONAL->value)
                ->and($field->getInputNumberFormat())->toBe(PhoneInputNumberType::E164->value);

            return true;
        });
});

it('defers the turnstile token until the form is submitted', function (): void {
    $html = Livewire::test(Create::class)->html();

    expect($html)
        ->toContain('wire:model="data.captcha_token"')
        ->not->toContain('wire:model.live="data.captcha_token"');
});

it('clears stale space selections and refreshes options when the venue changes', function (): void {
    $firstVenue = Venue::factory()->create(['status' => 'verified']);
    $secondVenue = Venue::factory()->create(['status' => 'verified']);
    $firstSpace = Space::factory()->create(['venue_id' => $firstVenue->getKey()]);
    $secondSpace = Space::factory()->create(['venue_id' => $secondVenue->getKey()]);

    Livewire::test(Create::class)
        ->set('data.location_same_as_institution', false)
        ->set('data.location_type', 'venue')
        ->set('data.location_venue_id', $firstVenue->getKey())
        ->set('data.space_ids', [$firstSpace->getKey()])
        ->set('data.location_venue_id', $secondVenue->getKey())
        ->assertSet('data.space_ids', [])
        ->assertFormFieldExists('space_ids', function (Select $field) use ($firstSpace, $secondSpace): bool {
            expect($field->getOptions())
                ->toHaveKey($secondSpace->getKey())
                ->not->toHaveKey($firstSpace->getKey());

            return true;
        });
});

it('clears selected spaces when the event becomes online', function (): void {
    $space = Space::factory()->create();

    Livewire::test(Create::class)
        ->set('data.space_ids', [$space->getKey()])
        ->set('data.event_format', EventFormat::Online->value)
        ->assertSet('data.space_ids', []);
});
