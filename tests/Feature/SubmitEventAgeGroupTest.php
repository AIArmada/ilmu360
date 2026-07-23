<?php

use App\Enums\EventAgeGroup;
use App\Livewire\Pages\SubmitEvent\Create;
use Livewire\Livewire;

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
