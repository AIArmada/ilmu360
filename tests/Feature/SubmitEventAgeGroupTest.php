<?php

use App\Enums\EventAgeGroup;
use App\Livewire\Pages\SubmitEvent\Create;
use Filament\Forms\Components\Select;
use Livewire\Livewire;

it('normalizes age-group choices in the browser without live synchronization', function (): void {
    Livewire::test(Create::class)
        ->assertFormFieldExists('age_group', function (Select $field): bool {
            $scripts = implode("\n", $field->getAfterStateUpdatedJs());

            expect($field->isLive())->toBeFalse()
                ->and($scripts)
                ->toContain(
                    'previousAgeGroups',
                    'specificAgeGroups',
                    'ageGroups.length === 1',
                    "\$set('age_group', normalizedAgeGroups)",
                );

            return true;
        });
});

it('collapses all specific age groups to AllAges as a server-side fallback', function (): void {
    setSubmitEventFormState(
        Livewire::test(Create::class),
        [
            'age_group' => [
                EventAgeGroup::Adults->value,
                EventAgeGroup::Youth->value,
                EventAgeGroup::Children->value,
                EventAgeGroup::Seniors->value,
            ],
        ],
    )->assertFormSet([
        'age_group' => [EventAgeGroup::AllAges],
    ]);
});

it('keeps AllAges selected when it is the only age-group choice', function (): void {
    setSubmitEventFormState(
        Livewire::test(Create::class),
        [
            'age_group' => [EventAgeGroup::AllAges->value],
        ],
    )->assertFormSet([
        'age_group' => [EventAgeGroup::AllAges],
    ]);
});

it('automatically sets and disables children_allowed when Children or AllAges is selected', function () {
    setSubmitEventFormState(
        Livewire::test(Create::class),
        [
            'age_group' => [EventAgeGroup::Children->value],
        ],
    )
        ->assertFormSet([
            'children_allowed' => true,
        ]);
});

it('keeps children_allowed configurable for non-children age groups', function () {
    setSubmitEventFormState(
        Livewire::test(Create::class),
        [
            'age_group' => [EventAgeGroup::Adults->value],
            'children_allowed' => false,
        ],
    )
        ->assertFormSet([
            'age_group' => [EventAgeGroup::Adults],
            'children_allowed' => false,
        ]);
});
