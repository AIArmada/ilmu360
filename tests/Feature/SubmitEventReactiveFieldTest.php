<?php

use App\Livewire\Pages\SubmitEvent\Create;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Livewire\Livewire;

it('keeps organizer synchronization in the browser without live requests', function (): void {
    Livewire::test(Create::class)
        ->assertFormFieldExists('primary_organizer_kind', function (Radio $field): bool {
            expect($field->isLive())->toBeFalse()
                ->and(implode("\n", $field->getAfterStateUpdatedJs()))
                ->toContain('primary_organizer_institution_id', 'primary_organizer_person_id');

            return true;
        })
        ->assertFormFieldExists('primary_organizer_institution_id', function (Select $field): bool {
            expect($field->isLive())->toBeFalse()
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
        ->assertFormFieldExists('submitter_phone', function (TextInput $field): bool {
            expect($field->isLive())->toBeFalse()
                ->and($field->getExtraAlpineAttributes())
                ->toHaveKey('x-bind:required');

            return true;
        });
});

it('defers the turnstile token until the form is submitted', function (): void {
    $html = Livewire::test(Create::class)->html();

    expect($html)
        ->toContain('wire:model="data.captcha_token"')
        ->not->toContain('wire:model.live="data.captcha_token"');
});
