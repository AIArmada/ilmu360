<?php

declare(strict_types=1);

namespace App\Features\Events\EntryPoints\Livewire\Pages\Dashboard\Events;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Models\Event;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventSession;
use App\Features\Contributions\Api\Enums\ContributionSubjectType;
use App\Features\Events\Actions\Schedule\SaveOccurrenceAction;
use App\Features\Events\Actions\Schedule\SaveSessionAction;
use App\Features\Events\Actions\Schedule\SubmitScheduleOccurrenceForReviewAction;
use App\Features\Events\Support\EventModel;
use App\Models\User;
use App\Platform\Ui\InteractsWithToasts;
use App\Shared\Signals\SignalRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

#[Layout('layouts.app')]
#[Title('Event schedule')]
final class Schedule extends Component
{
    use InteractsWithToasts;

    #[Locked]
    public string $eventId = '';

    public bool $showOccurrenceForm = false;

    public bool $showSessionForm = false;

    public ?string $editingOccurrenceId = null;

    public ?string $editingSessionId = null;

    public string $sessionOccurrenceId = '';

    /** @var array<string, mixed> */
    public array $occurrenceForm = [];

    /** @var array<string, mixed> */
    public array $sessionForm = [];

    public function boot(): void
    {
        OwnerContext::setForRequest(null);
    }

    public function mount(Event $event): void
    {
        $this->eventId = (string) $event->getKey();
        $event = $this->selectedEvent();

        $this->authorizeSchedule($event);
        $this->occurrenceForm = $this->defaultOccurrenceForm($event);
        $this->sessionForm = $this->defaultSessionForm($event);
    }

    public function openOccurrenceForm(): void
    {
        $event = $this->selectedEvent();
        $this->authorizeSchedule($event);
        abort_unless($this->canEditSchedule($event) || $this->canProposeSchedule($event), 403);

        $this->editingOccurrenceId = null;
        $this->occurrenceForm = $this->defaultOccurrenceForm($event);
        $this->showOccurrenceForm = true;
        $this->showSessionForm = false;
        $this->resetValidation();
    }

    public function editOccurrence(string $occurrenceId): void
    {
        $event = $this->selectedEvent();
        $this->authorizeSchedule($event);
        abort_unless($this->canEditSchedule($event) || $this->canProposeSchedule($event), 403);
        $occurrence = $this->occurrenceForEvent($event, $occurrenceId);
        abort_unless($this->canEditSchedule($event) || (string) $occurrence->status === EventOccurrence::SCHEDULED, 403);
        $timezone = $this->validTimezone((string) ($occurrence->timezone ?: $event->timezone));

        $this->editingOccurrenceId = (string) $occurrence->getKey();
        $this->occurrenceForm = [
            'title' => (string) $occurrence->title,
            'starts_at' => $this->toLocalInput($occurrence->starts_at, $timezone),
            'ends_at' => $this->toLocalInput($occurrence->ends_at, $timezone),
            'timezone' => $timezone,
            'capacity' => $occurrence->capacity === null ? '' : (string) $occurrence->capacity,
        ];
        $this->showOccurrenceForm = true;
        $this->showSessionForm = false;
        $this->resetValidation();
    }

    public function closeOccurrenceForm(): void
    {
        $this->editingOccurrenceId = null;
        $this->showOccurrenceForm = false;
        $this->resetValidation();
    }

    public function saveOccurrence(
        SaveOccurrenceAction $saveOccurrence,
        SubmitScheduleOccurrenceForReviewAction $submitForReview,
        SignalRecorder $signals,
    ): void {
        $event = $this->selectedEvent();
        $this->authorizeSchedule($event);
        $propose = ! $this->canEditSchedule($event);
        abort_if($propose && ! $this->canProposeSchedule($event), 403);

        $validated = $this->validate($this->occurrenceRules());
        $data = $validated['occurrenceForm'];
        $timezone = $this->validTimezone((string) $data['timezone']);
        $attributes = [
            'title' => (string) $data['title'],
            'starts_at' => $this->toUtcString((string) $data['starts_at'], $timezone),
            'ends_at' => filled($data['ends_at'] ?? null) ? $this->toUtcString((string) $data['ends_at'], $timezone) : null,
            'timezone' => $timezone,
            'capacity' => filled($data['capacity'] ?? null) ? (int) $data['capacity'] : null,
        ];

        if ($propose) {
            $actor = auth()->user();
            abort_unless($actor instanceof User, 403);
            $note = trim((string) ($data['note'] ?? ''));
            if ($this->editingOccurrenceId === null) {
                $occurrence = $submitForReview->handle((string) $event->getKey(), $actor, $attributes, $note === '' ? null : $note);
                $occurrenceId = (string) $occurrence->getKey();
            } else {
                $occurrenceId = $this->editingOccurrenceId;
                $submitForReview->proposeChange((string) $event->getKey(), $actor, $occurrenceId, false, null, $attributes, $note === '' ? null : $note);
            }

            $signals->record(
                eventName: 'event.occurrence_review_requested',
                eventCategory: 'event_management',
                actorId: (string) $actor->getKey(),
                properties: ['event_id' => (string) $event->getKey(), 'event_occurrence_id' => $occurrenceId],
                request: request(),
            );

            $this->closeOccurrenceForm();
            $this->successToast(__('Occurrence submitted for review.'));

            return;
        }

        try {
            if ($this->editingOccurrenceId !== null) {
                $saveOccurrence->handle($event, $attributes, $this->editingOccurrenceId);
                $message = __('Occurrence updated successfully.');
            } else {
                $saveOccurrence->handle($event, [
                    ...$attributes,
                    'visibility' => $this->eventVisibility($event),
                    'delivery_mode' => (string) ($event->delivery_mode ?: 'physical'),
                ]);
                $message = __('Occurrence added successfully.');
            }
        } catch (Throwable $throwable) {
            report($throwable);
            $this->errorToast(__('The occurrence could not be saved. Please check the details and try again.'));

            return;
        }

        $this->closeOccurrenceForm();
        $this->successToast($message);
    }

    public function openSessionForm(string $occurrenceId): void
    {
        $event = $this->selectedEvent();
        $this->authorizeSchedule($event);
        abort_unless($this->canEditSchedule($event) || $this->canProposeSchedule($event), 403);
        $occurrence = $this->occurrenceForEvent($event, $occurrenceId);
        abort_unless($this->canEditSchedule($event) || (string) $occurrence->status === EventOccurrence::SCHEDULED, 403);

        $this->editingSessionId = null;
        $this->sessionOccurrenceId = (string) $occurrence->getKey();
        $this->sessionForm = $this->defaultSessionForm($event, $occurrence);
        $this->showSessionForm = true;
        $this->showOccurrenceForm = false;
        $this->resetValidation();
    }

    public function editSession(string $sessionId): void
    {
        $event = $this->selectedEvent();
        $this->authorizeSchedule($event);
        abort_unless($this->canEditSchedule($event) || $this->canProposeSchedule($event), 403);
        $session = $this->sessionForEvent($event, $sessionId);
        $occurrenceId = (string) ($session->event_occurrence_id ?? '');

        if ($occurrenceId === '') {
            throw ValidationException::withMessages([
                'sessionForm.title' => __('This session is not attached to an occurrence.'),
            ]);
        }

        $occurrence = $this->occurrenceForEvent($event, $occurrenceId);
        abort_unless($this->canEditSchedule($event) || (string) $occurrence->status === EventOccurrence::SCHEDULED, 403);

        $timezone = $this->validTimezone((string) ($session->timezone ?: $event->timezone));
        $this->editingSessionId = (string) $session->getKey();
        $this->sessionOccurrenceId = $occurrenceId;
        $this->sessionForm = [
            'title' => (string) $session->title,
            'summary' => (string) ($session->summary ?? ''),
            'description' => (string) ($session->description ?? ''),
            'starts_at' => $this->toLocalInput($session->starts_at, $timezone),
            'ends_at' => $this->toLocalInput($session->ends_at, $timezone),
            'timezone' => $timezone,
            'capacity' => $session->capacity === null ? '' : (string) $session->capacity,
        ];
        $this->showSessionForm = true;
        $this->showOccurrenceForm = false;
        $this->resetValidation();
    }

    public function closeSessionForm(): void
    {
        $this->editingSessionId = null;
        $this->sessionOccurrenceId = '';
        $this->showSessionForm = false;
        $this->resetValidation();
    }

    public function saveSession(
        SaveSessionAction $saveSession,
        SubmitScheduleOccurrenceForReviewAction $submitForReview,
        SignalRecorder $signals,
    ): void {
        $event = $this->selectedEvent();
        $this->authorizeSchedule($event);
        $propose = ! $this->canEditSchedule($event);
        abort_if($propose && ! $this->canProposeSchedule($event), 403);
        $validated = $this->validate($this->sessionRules());
        $data = $validated['sessionForm'];
        $timezone = $this->validTimezone((string) $data['timezone']);
        $attributes = [
            'title' => (string) $data['title'],
            'summary' => filled($data['summary'] ?? null) ? (string) $data['summary'] : null,
            'description' => filled($data['description'] ?? null) ? (string) $data['description'] : null,
            'starts_at' => $this->toUtcString((string) $data['starts_at'], $timezone),
            'ends_at' => filled($data['ends_at'] ?? null) ? $this->toUtcString((string) $data['ends_at'], $timezone) : null,
            'timezone' => $timezone,
            'capacity' => filled($data['capacity'] ?? null) ? (int) $data['capacity'] : null,
        ];

        if ($propose) {
            $actor = auth()->user();
            abort_unless($actor instanceof User, 403);
            $note = trim((string) ($data['note'] ?? ''));

            $submitForReview->proposeChange(
                (string) $event->getKey(),
                $actor,
                $this->sessionOccurrenceId,
                true,
                $this->editingSessionId,
                $attributes,
                $note === '' ? null : $note,
            );
            $signals->record(
                eventName: 'event_session_review_requested',
                eventCategory: 'event_management',
                actorId: (string) $actor->getKey(),
                properties: ['event_id' => (string) $event->getKey(), 'occurrence_id' => $this->sessionOccurrenceId],
                request: request(),
            );

            $this->closeSessionForm();
            $this->successToast(__('Session submitted for review.'));

            return;
        }

        try {
            if ($this->editingSessionId !== null) {
                $saveSession->handle($event, $this->sessionOccurrenceId, $attributes, $this->editingSessionId);
                $message = __('Session updated successfully.');
            } else {
                $saveSession->handle($event, $this->sessionOccurrenceId, [
                    ...$attributes,
                    'visibility' => $this->eventVisibility($event),
                    'delivery_mode' => (string) ($event->delivery_mode ?: 'physical'),
                ]);
                $message = __('Session added successfully.');
            }
        } catch (Throwable $throwable) {
            report($throwable);
            $this->errorToast(__('The session could not be saved. Please check the details and try again.'));

            return;
        }

        $this->closeSessionForm();
        $this->successToast($message);
    }

    /** @return Collection<int, EventOccurrence> */
    public function occurrences(): Collection
    {
        $event = $this->selectedEvent();

        /** @var Collection<int, EventOccurrence> $occurrences */
        $occurrences = $event->occurrences()
            ->with('sessions')
            ->orderBy('starts_at')
            ->get();

        return $occurrences;
    }

    public function render(): View
    {
        $event = $this->selectedEvent();

        return view('livewire.pages.dashboard.events.schedule', [
            'event' => $event,
            'occurrences' => $this->occurrences(),
            'eventTimezone' => $this->validTimezone((string) ($event->timezone ?: config('app.timezone', 'UTC'))),
            'canEditSchedule' => $this->canEditSchedule($event),
            'canProposeSchedule' => $this->canProposeSchedule($event),
            'suggestUpdateUrl' => route('contributions.suggest-update', [
                'subjectType' => ContributionSubjectType::Event->publicRouteSegment(),
                'subjectId' => $event->slug,
            ]),
        ]);
    }

    /** @return array<string, array<int, mixed>> */
    private function occurrenceRules(): array
    {
        return [
            'occurrenceForm.title' => ['required', 'string', 'max:255'],
            'occurrenceForm.starts_at' => ['required', 'date'],
            'occurrenceForm.ends_at' => ['nullable', 'date', 'after:occurrenceForm.starts_at'],
            'occurrenceForm.timezone' => ['required', 'timezone'],
            'occurrenceForm.capacity' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'occurrenceForm.note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    private function sessionRules(): array
    {
        return [
            'sessionOccurrenceId' => ['required', 'uuid'],
            'sessionForm.title' => ['required', 'string', 'max:255'],
            'sessionForm.summary' => ['nullable', 'string', 'max:1000'],
            'sessionForm.description' => ['nullable', 'string', 'max:10000'],
            'sessionForm.starts_at' => ['required', 'date'],
            'sessionForm.ends_at' => ['nullable', 'date', 'after:sessionForm.starts_at'],
            'sessionForm.timezone' => ['required', 'timezone'],
            'sessionForm.capacity' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'sessionForm.note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, mixed> */
    private function defaultOccurrenceForm(Event $event): array
    {
        $timezone = $this->validTimezone((string) ($event->timezone ?: config('app.timezone', 'UTC')));
        $startsAt = CarbonImmutable::now($timezone)->addDays(7)->setTime(20, 0);

        return [
            'title' => '',
            'starts_at' => $startsAt->format('Y-m-d\TH:i'),
            'ends_at' => $startsAt->addHours(2)->format('Y-m-d\TH:i'),
            'timezone' => $timezone,
            'capacity' => '',
            'note' => '',
        ];
    }

    /** @return array<string, mixed> */
    private function defaultSessionForm(Event $event, ?EventOccurrence $occurrence = null): array
    {
        $timezone = $this->validTimezone((string) ($occurrence?->timezone ?: $event->timezone ?: config('app.timezone', 'UTC')));
        $startsAt = $occurrence?->starts_at instanceof CarbonImmutable
            ? $occurrence->starts_at->copy()->setTimezone($timezone)
            : CarbonImmutable::now($timezone)->addDays(7)->setTime(20, 0);

        return [
            'title' => '',
            'summary' => '',
            'description' => '',
            'starts_at' => $startsAt->format('Y-m-d\TH:i'),
            'ends_at' => $startsAt->addHour()->format('Y-m-d\TH:i'),
            'timezone' => $timezone,
            'capacity' => '',
        ];
    }

    private function canEditSchedule(Event $event): bool
    {
        if ($event->published_at === null && (string) $event->status !== 'approved') {
            return true;
        }

        $actor = auth()->user();

        return $actor instanceof User && $actor->canPublishDirectoryUpdates();
    }

    private function canProposeSchedule(Event $event): bool
    {
        $actor = auth()->user();

        return (string) $event->status === 'approved'
            && $actor instanceof User
            && $actor->canSubmitDirectoryFeedback();
    }

    private function authorizeSchedule(Event $event): void
    {
        $actorId = auth()->id();
        abort_unless(is_string($actorId) && $actorId !== '', 403);

        $actor = EventModel::query('user')->find($actorId);
        abort_unless($actor instanceof Authenticatable, 403);
        abort_unless(Gate::forUser($actor)->allows('update', $event), 403);
    }

    private function selectedEvent(): Event
    {
        /** @var Event $event */
        $event = EventModel::query('event')->whereKey($this->eventId)->firstOrFail();

        return $event;
    }

    private function occurrenceForEvent(Event $event, string $occurrenceId): EventOccurrence
    {
        /** @var EventOccurrence $occurrence */
        $occurrence = $event->occurrences()
            ->whereKey($occurrenceId)
            ->firstOrFail();

        return $occurrence;
    }

    private function sessionForEvent(Event $event, string $sessionId): EventSession
    {
        /** @var EventSession $session */
        $session = EventSession::query()
            ->whereKey($sessionId)
            ->where('event_id', $event->getKey())
            ->firstOrFail();

        return $session;
    }

    private function toUtcString(string $value, string $timezone): string
    {
        return CarbonImmutable::parse($value, $timezone)->utc()->toIso8601String();
    }

    private function toLocalInput(mixed $value, string $timezone): string
    {
        if ($value === null) {
            return '';
        }

        if (! $value instanceof CarbonImmutable) {
            $value = CarbonImmutable::parse((string) $value);
        }

        return $value->setTimezone($timezone)->format('Y-m-d\TH:i');
    }

    private function validTimezone(string $timezone): string
    {
        try {
            CarbonImmutable::now($timezone);

            return $timezone;
        } catch (Throwable) {
            return 'UTC';
        }
    }

    private function eventVisibility(Event $event): string
    {
        return $event->visibility instanceof \BackedEnum
            ? $event->visibility->value
            : (string) $event->visibility;
    }
}
