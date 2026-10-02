<?php

use App\Enums\InstitutionNameType;
use App\Livewire\Pages\SubmitEvent\Create;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Space;
use App\Models\Venue;
use Database\Seeders\AIArmada\EventRoleSeeder;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(EventRoleSeeder::class);
});

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

it('does not advertise a pending submission as publicly accessible', function (string $visibility) {
    $this->withSession([
        'event_title' => 'Pending Submission',
        'event_slug' => 'pending-submission',
        'event_status' => 'pending',
        'event_visibility' => $visibility,
        'event_auto_approved' => false,
    ])->get(route('submit-event.success'))
        ->assertSuccessful()
        ->assertSee(__('Event Submitted!'))
        ->assertDontSee(route('events.show', 'pending-submission'))
        ->assertDontSee(__('Majlis anda kini disiarkan dan boleh dicari secara terus oleh orang awam.'))
        ->assertSee(__('Pasukan moderator kami akan menyemak butiran majlis dalam masa 24-48 jam untuk tujuan pengesahan.'));
})->with(['public', 'unlisted']);

it('links a published session to its own page on the submission confirmation', function () {
    $this->withSession([
        'event_title' => 'Added Session',
        'event_slug' => 'parent-event',
        'event_status' => 'approved',
        'event_visibility' => 'public',
        'event_auto_approved' => false,
        'event_container_id' => (string) Str::uuid(),
        'event_container_title' => 'Parent Event',
        'event_session_id' => (string) Str::uuid(),
        'event_session_slug' => 'added-session',
        'event_occurrence_slug' => 'event-date',
    ])->get(route('submit-event.success'))
        ->assertSuccessful()
        ->assertSee(__('Session Added'))
        ->assertSee(route('events.session', ['parent-event', 'event-date', 'added-session']))
        ->assertDontSee(__('Terima kasih atas perkongsian anda! Pasukan kami akan menyemak butirannya dalam masa 24-48 jam.'));
});

it('does not advertise a session under a private parent scope', function (string $scope) {
    $this->withSession([
        'event_title' => 'Private Scope Session',
        'event_slug' => 'private-scope-event',
        'event_status' => 'approved',
        'event_visibility' => 'public',
        'event_parent_visibility' => $scope === 'event' ? 'private' : 'public',
        'event_occurrence_visibility' => $scope === 'occurrence' ? 'private' : 'public',
        'event_session_id' => (string) Str::uuid(),
        'event_session_slug' => 'own-session',
        'event_occurrence_slug' => 'own-occurrence',
    ])->get(route('submit-event.success'))
        ->assertSuccessful()
        ->assertDontSee(route('events.session', ['private-scope-event', 'own-occurrence', 'own-session']))
        ->assertDontSee(__('Pasukan moderator kami akan menyemak butiran majlis dalam masa 24-48 jam untuk tujuan pengesahan.'))
        ->assertDontSee(__('Majlis anda kini disiarkan dan boleh dicari secara terus oleh orang awam.'));
})->with(['event', 'occurrence']);
