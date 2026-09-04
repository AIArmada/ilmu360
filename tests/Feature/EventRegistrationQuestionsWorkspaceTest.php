<?php

declare(strict_types=1);

use AIArmada\Events\Enums\EventRegistrationQuestionStatus;
use AIArmada\Events\Models\EventRegistrationQuestion;
use App\Livewire\Pages\Dashboard\Events\RegistrationQuestions;
use App\Models\Event;
use App\Models\User;
use Livewire\Livewire;

it('lets an event editor author questions for the event and a session', function (): void {
    $organizer = User::factory()->create();
    $this->actingAs($organizer);

    $event = Event::factory()->create([
        'title' => 'Questions Workspace Event',
        'status' => 'draft',
        'created_by_type' => $organizer->getMorphClass(),
        'created_by_id' => $organizer->getKey(),
    ]);
    $occurrence = $event->occurrences()->create([
        'title' => 'Main date',
        'slug' => 'main-date',
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addHours(2),
        'timezone' => 'Asia/Kuala_Lumpur',
        'status' => 'scheduled',
        'visibility' => 'public',
        'delivery_mode' => 'physical',
    ]);
    $session = $occurrence->sessions()->create([
        'event_id' => $event->getKey(),
        'title' => 'Opening session',
        'slug' => 'opening-session',
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addHour(),
        'timezone' => 'Asia/Kuala_Lumpur',
        'status' => 'scheduled',
        'visibility' => 'public',
        'delivery_mode' => 'physical',
    ]);

    $component = Livewire::actingAs($organizer)
        ->test(RegistrationQuestions::class, ['event' => $event])
        ->call('openQuestionForm')
        ->set('questionForm.question', 'Dietary requirements')
        ->set('questionForm.type', 'select')
        ->set('questionForm.options', "Halal\nVegetarian")
        ->set('questionForm.is_required', true)
        ->call('saveQuestion')
        ->assertHasNoErrors();

    $eventQuestion = EventRegistrationQuestion::query()
        ->where('event_id', $event->getKey())
        ->whereNull('event_occurrence_id')
        ->firstOrFail();

    expect($eventQuestion->field_key)->toBe('dietary_requirements')
        ->and($eventQuestion->options)->toBe(['Halal', 'Vegetarian'])
        ->and($eventQuestion->is_required)->toBeTrue();

    $component
        ->call('openQuestionForm')
        ->set('questionForm.scope', 'session')
        ->set('questionForm.occurrence_id', (string) $occurrence->getKey())
        ->set('questionForm.session_id', (string) $session->getKey())
        ->set('questionForm.question', 'Accessibility needs')
        ->call('saveQuestion')
        ->assertHasNoErrors();

    expect(EventRegistrationQuestion::query()
        ->where('event_id', $event->getKey())
        ->where('event_session_id', $session->getKey())
        ->exists())->toBeTrue();

    $component
        ->call('editQuestion', (string) $eventQuestion->getKey())
        ->set('questionForm.question', 'Dietary or meal requirements')
        ->call('saveQuestion')
        ->assertHasNoErrors();

    $component->call('archiveQuestion', (string) $eventQuestion->getKey());

    expect($eventQuestion->fresh()->status)->toBe(EventRegistrationQuestionStatus::Archived)
        ->and($eventQuestion->fresh()->archived_at)->not->toBeNull();
});

it('shows the registration questions workspace and blocks an outsider', function (): void {
    $organizer = User::factory()->create();
    $outsider = User::factory()->create();
    $event = Event::factory()->create([
        'title' => 'Question Route Event',
        'status' => 'draft',
        'created_by_type' => $organizer->getMorphClass(),
        'created_by_id' => $organizer->getKey(),
    ]);

    $this->actingAs($organizer)
        ->get(route('dashboard.events.registration-questions', $event))
        ->assertOk()
        ->assertSee('Question Route Event')
        ->assertSee(__('Questions for participants'));

    $this->actingAs($outsider)
        ->get(route('dashboard.events.registration-questions', $event))
        ->assertForbidden();
});
