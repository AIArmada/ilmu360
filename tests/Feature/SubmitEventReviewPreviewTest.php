<?php

use Livewire\Livewire;

it('shows a submission preview section on submit event page', function () {
    $this->get(route('submit-event.create'))
        ->assertSuccessful()
        ->assertSee(__('Pratonton Penghantaran'))
        ->assertSee(__('Semak ringkasan ini sebelum anda menghantar.'))
        ->assertSee(__('Seterusnya'));
});

it('scopes the next action loading target to the wizard next step only', function () {
    $this->get(route('submit-event.create', ['step' => 'form.penceramah-media::data::wizard-step']))
        ->assertSuccessful()
        ->assertSee("callSchemaComponentMethod('form.data::wizard', 'nextStep')", false)
        ->assertDontSee('window.__submitEventReviewRefreshed', false)
        ->assertDontSee('$wire.$refresh()', false);
});

it('hides the next action when the review step rerenders', function () {
    $reviewStepId = 'form.semak-sebelum-hantar::data::wizard-step';

    Livewire::withQueryParams(['step' => $reviewStepId])
        ->test('pages.submit-event.create')
        ->assertSet('wizardStep', $reviewStepId)
        ->call('$refresh')
        ->assertSee(__('Sebelum'))
        ->assertSee(__('Hantar Majlis untuk Semakan'))
        ->assertDontSee(__('Seterusnya'));
});

it('loads app-level filament helper scripts on submit event page', function () {
    $this->get(route('submit-event.create'))
        ->assertSuccessful()
        ->assertSee('close-on-select.js', false)
        ->assertSee('user-timezone.js', false);
});
