<?php

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Filament\Ahli\Widgets\PendingApprovalEventsWidget;
use App\Filament\Pages\AhliDashboard;
use App\Models\Event;
use App\Models\EventSubmission;
use App\Models\Institution;
use App\Models\Person;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel('ahli');
});

it('shows only pending public-submitted events from member institutions and speakers on the ahli dashboard widget', function () {
    $user = User::factory()->create();
    $memberInstitution = Institution::factory()->create();
    $memberPerson = Person::factory()->create();
    $outsidePerson = Person::factory()->create();
    $outsideInstitution = Institution::factory()->create();

    $memberInstitution->members()->syncWithoutDetaching([$user->id]);
    $memberPerson->members()->syncWithoutDetaching([$user->id]);

    $institutionEvent = Event::factory()->create([
        'title' => 'Institution Pending Approval',
        'status' => 'pending',
    ]);
    OwnerContext::withOwner(null, fn () => $institutionEvent->setPrimaryOrganizer($memberInstitution));

    $personEvent = Event::factory()->create([
        'title' => 'Person Pending Approval',
        'status' => 'pending',
    ]);
    OwnerContext::withOwner(null, fn () => $personEvent->setPrimaryOrganizer($memberPerson));

    $institutionLinkedPersonEvent = Event::factory()->for($memberInstitution)->create([
        'title' => 'Institution Linked Person Pending Approval',
        'status' => 'pending',
    ]);
    OwnerContext::withOwner(null, fn () => $institutionLinkedPersonEvent->setPrimaryOrganizer($outsidePerson));

    $outsideEvent = Event::factory()->create([
        'title' => 'Outside Pending Approval',
        'status' => 'pending',
    ]);
    OwnerContext::withOwner(null, fn () => $outsideEvent->setPrimaryOrganizer($outsideInstitution));

    $draftEvent = Event::factory()->create([
        'title' => 'Draft Institution Event',
        'status' => 'draft',
    ]);
    OwnerContext::withOwner(null, fn () => $draftEvent->setPrimaryOrganizer($memberInstitution));

    $pendingWithoutSubmission = Event::factory()->create([
        'title' => 'Pending Without Submission',
        'status' => 'pending',
    ]);
    OwnerContext::withOwner(null, fn () => $pendingWithoutSubmission->setPrimaryOrganizer($memberInstitution));

    EventSubmission::factory()->for($institutionEvent)->create();
    EventSubmission::factory()->for($personEvent)->create();
    EventSubmission::factory()->for($institutionLinkedPersonEvent)->create();
    EventSubmission::factory()->for($outsideEvent)->create();
    EventSubmission::factory()->for($draftEvent)->create();

    OwnerContext::withOwner(null, fn () => Livewire::actingAs($user)
        ->test(PendingApprovalEventsWidget::class)
        ->assertCountTableRecords(3)
        ->assertCanSeeTableRecords([$institutionEvent, $personEvent, $institutionLinkedPersonEvent])
        ->assertCanNotSeeTableRecords([$outsideEvent, $draftEvent, $pendingWithoutSubmission]));
});

it('renders the ahli dashboard with the pending approval queue for member scopes', function () {
    $user = User::factory()->create();
    $institution = Institution::factory()->create();

    $institution->members()->syncWithoutDetaching([$user->id]);

    $event = Event::factory()->create([
        'title' => 'Dashboard Approval Event',
        'status' => 'pending',
    ]);
    OwnerContext::withOwner(null, fn () => $event->setPrimaryOrganizer($institution));

    EventSubmission::factory()->for($event)->create();

    OwnerContext::withOwner(null, fn () => $this->actingAs($user)
        ->get(AhliDashboard::getUrl(panel: 'ahli'))
        ->assertSuccessful()
        ->assertSee('Events Needing Approval')
        ->assertSee('Dashboard Approval Event'));
});

it('links submitter phone numbers to whatsapp in the ahli approval widget', function () {
    $user = User::factory()->create();
    $submitter = User::factory()->create([
        'phone' => '60123456789',
    ]);
    $institution = Institution::factory()->create();

    $institution->members()->syncWithoutDetaching([$user->id]);

    $event = Event::factory()->create([
        'title' => 'Widget WhatsApp Contact Event',
        'status' => 'pending',
    ]);
    OwnerContext::withOwner(null, fn () => $event->setPrimaryOrganizer($institution));

    EventSubmission::query()->create([
        'event_id' => $event->id,
        'status' => 'pending',
        'submitted_at' => now(),
        'submitter_type' => $submitter->getMorphClass(),
        'submitter_id' => $submitter->id,
        'submission_data' => ['submitter_name' => $submitter->name],
    ]);

    OwnerContext::withOwner(null, fn () => Livewire::actingAs($user)
        ->test(PendingApprovalEventsWidget::class)
        ->assertSee('https://wa.me/60123456789'));
});
