<?php

use App\Enums\InstitutionNameType;
use App\Livewire\Pages\SubmitEvent\Create;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Space;
use App\Models\Venue;
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
        ->test(Create::class)
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

it('previews resolved country, organizer, venue, spaces, and speakers on the review step', function () {
    $country = ensureTestMalaysiaCountry();
    $person = Person::factory()->create([
        'status' => 'verified',
        'allow_public_event_submission' => true,
    ]);
    $institution = Institution::factory()->create([
        'status' => 'verified',
        'allow_public_event_submission' => true,
    ]);
    $institution->names()->create([
        'name_type' => InstitutionNameType::Nickname,
        'full_name' => 'Gelanggang Preview Unik',
        'language_code' => 'ms',
        'is_primary' => true,
    ]);
    $venue = Venue::factory()->create(['status' => 'verified']);
    $space = Space::factory()->create(['name' => 'Dewan Preview Semak']);

    $component = Livewire::withQueryParams(['step' => 'form.semak-sebelum-hantar::data::wizard-step'])
        ->test(Create::class);

    setSubmitEventFormState($component, [
        'submission_country_id' => (string) $country->getKey(),
        'primary_organizer_kind' => 'person',
        'primary_organizer_person_id' => $person->id,
        'primary_organizer_id' => $person->id,
        'location_same_as_institution' => false,
        'location_type' => 'venue',
        'location_venue_id' => $venue->id,
        'space_ids' => [$space->id],
        'persons' => [$person->id],
    ]);

    $component
        ->assertSee($country->name)
        ->assertSee($venue->name)
        ->assertSee($space->name)
        ->assertSee($person->formatted_name)
        ->assertSeeHtml(
            '<dd class="mt-1 text-sm leading-relaxed break-words font-medium text-slate-900">'.e(__('Penceramah')).'</dd>',
            false,
        );

    $component
        ->set('data.primary_organizer_kind', 'institution')
        ->set('data.primary_organizer_institution_id', $institution->id)
        ->set('data.primary_organizer_id', $institution->id)
        ->assertSee($institution->refresh()->display_name)
        ->assertSeeHtml(
            '<dd class="mt-1 text-sm leading-relaxed break-words font-medium text-slate-900">'.e(__('Institusi')).'</dd>',
            false,
        );
});
