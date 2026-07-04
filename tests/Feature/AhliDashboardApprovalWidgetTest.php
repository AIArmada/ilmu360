<?php

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Filament\Ahli\Widgets\PendingApprovalEventsWidget;
use App\Filament\Pages\AhliDashboard;
use App\Models\Event;
use App\Models\EventSubmission;
use App\Models\Institution;
use App\Models\Speaker;
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
    $memberSpeaker = Speaker::factory()->create();
    $outsideSpeaker = Speaker::factory()->create();
    $outsideInstitution = Institution::factory()->create();

    $memberInstitution->members()->syncWithoutDetaching([$user->id]);
    $memberSpeaker->members()->syncWithoutDetaching([$user->id]);

    $institutionEvent = Event::factory()->create([
        'title' => 'Institution Pending Approval',
        'status' => 'pending',
    ]);
    OwnerContext::withOwner(null, fn () => $institutionEvent->setPrimaryOrganizer($memberInstitution));

    $speakerEvent = Event::factory()->create([
        'title' => 'Speaker Pending Approval',
        'status' => 'pending',
    ]);
    OwnerContext::withOwner(null, fn () => $speakerEvent->setPrimaryOrganizer($memberSpeaker));

    $institutionLinkedSpeakerEvent = Event::factory()->for($memberInstitution)->create([
        'title' => 'Institution Linked Speaker Pending Approval',
        'status' => 'pending',
    ]);
    OwnerContext::withOwner(null, fn () => $institutionLinkedSpeakerEvent->setPrimaryOrganizer($outsideSpeaker));

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
    EventSubmission::factory()->for($speakerEvent)->create();
    EventSubmission::factory()->for($institutionLinkedSpeakerEvent)->create();
    EventSubmission::factory()->for($outsideEvent)->create();
    EventSubmission::factory()->for($draftEvent)->create();

    OwnerContext::withOwner(null, fn () => Livewire::actingAs($user)
        ->test(PendingApprovalEventsWidget::class)
        ->assertCountTableRecords(3)
        ->assertCanSeeTableRecords([$institutionEvent, $speakerEvent, $institutionLinkedSpeakerEvent])
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
        'submitter_id' => $submitter->id,
    ]);
    OwnerContext::withOwner(null, fn () => $event->setPrimaryOrganizer($institution));

    EventSubmission::factory()
        ->for($event)
        ->for($submitter, 'submitter')
        ->create([
            'submitter_name' => $submitter->name,
        ]);

    OwnerContext::withOwner(null, fn () => Livewire::actingAs($user)
        ->test(PendingApprovalEventsWidget::class)
        ->assertSee('https://wa.me/60123456789'));
});
