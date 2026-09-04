<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Dashboard\Events;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Actions\ArchiveEventRegistrationQuestionAction;
use AIArmada\Events\Actions\CreateEventRegistrationQuestionAction;
use AIArmada\Events\Actions\UpdateEventRegistrationQuestionAction;
use AIArmada\Events\Enums\EventRegistrationQuestionType;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventRegistrationQuestion;
use AIArmada\Events\Models\EventSession;
use App\Livewire\Concerns\InteractsWithToasts;
use App\Models\Event;
use App\Models\User;
use App\Support\Timezone\UserDateTimeFormatter;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

#[Layout('layouts.app')]
#[Title('Registration questions')]
final class RegistrationQuestions extends Component
{
    use InteractsWithToasts;

    #[Locked]
    public string $eventId = '';

    public bool $showForm = false;

    public ?string $editingQuestionId = null;

    /** @var array{scope: string, occurrence_id: string, session_id: string, question: string, description: string, type: string, options: string, is_required: bool} */
    public array $questionForm = [
        'scope' => 'event',
        'occurrence_id' => '',
        'session_id' => '',
        'question' => '',
        'description' => '',
        'type' => 'text',
        'options' => '',
        'is_required' => false,
    ];

    public function boot(): void
    {
        OwnerContext::setForRequest(null);
    }

    public function mount(Event $event): void
    {
        $this->authorizeEvent($event);
        $this->eventId = (string) $event->getKey();
        $this->questionForm = $this->defaultQuestionForm();
    }

    public function updatedQuestionFormScope(mixed $value): void
    {
        $scope = $this->normalizeScope($value);
        $this->questionForm['scope'] = $scope;

        if ($scope === 'event') {
            $this->questionForm['occurrence_id'] = '';
            $this->questionForm['session_id'] = '';
        } elseif ($scope === 'occurrence') {
            $this->questionForm['session_id'] = '';

            if ($this->questionForm['occurrence_id'] === '') {
                $this->questionForm['occurrence_id'] = array_key_first($this->occurrenceOptions());
            }
        } elseif ($this->questionForm['occurrence_id'] === '') {
            $this->questionForm['occurrence_id'] = array_key_first($this->occurrenceOptions());
        }
    }

    public function updatedQuestionFormOccurrenceId(mixed $value): void
    {
        $this->questionForm['occurrence_id'] = is_string($value) ? $value : '';
        $this->questionForm['session_id'] = '';
    }

    public function openQuestionForm(): void
    {
        $event = $this->selectedEvent();
        $this->authorizeEvent($event);
        $this->editingQuestionId = null;
        $this->questionForm = $this->defaultQuestionForm();
        $this->showForm = true;
        $this->resetValidation();
    }

    public function editQuestion(string $questionId): void
    {
        $event = $this->selectedEvent();
        $this->authorizeEvent($event);
        $question = $this->questionForEvent($event, $questionId);

        $scope = $question->event_session_id !== null
            ? 'session'
            : ($question->event_occurrence_id !== null ? 'occurrence' : 'event');

        $this->editingQuestionId = (string) $question->getKey();
        $this->questionForm = [
            'scope' => $scope,
            'occurrence_id' => (string) ($question->event_occurrence_id ?? ''),
            'session_id' => (string) ($question->event_session_id ?? ''),
            'question' => (string) $question->question,
            'description' => (string) ($question->description ?? ''),
            'type' => $question->type->value,
            'options' => implode("\n", $question->options ?? []),
            'is_required' => $question->is_required,
        ];
        $this->showForm = true;
        $this->resetValidation();
    }

    public function closeQuestionForm(): void
    {
        $this->editingQuestionId = null;
        $this->showForm = false;
        $this->resetValidation();
    }

    public function saveQuestion(
        CreateEventRegistrationQuestionAction $createQuestion,
        UpdateEventRegistrationQuestionAction $updateQuestion,
    ): void {
        $event = $this->selectedEvent();
        $this->authorizeEvent($event);
        $validated = $this->validate($this->questionRules());
        $data = $validated['questionForm'];
        $type = EventRegistrationQuestionType::from((string) $data['type']);
        $options = $this->parseOptions((string) ($data['options'] ?? ''));

        if ($type->requiresOptions() && $options === []) {
            throw ValidationException::withMessages([
                'questionForm.options' => __('Add at least one option for a choice question.'),
            ]);
        }

        $attributes = [
            'question' => (string) $data['question'],
            'description' => filled($data['description'] ?? null) ? (string) $data['description'] : null,
            'type' => $type->value,
            'options' => $options,
            'is_required' => (bool) ($data['is_required'] ?? false),
        ];

        try {
            if ($this->editingQuestionId !== null) {
                $question = $this->questionForEvent($event, $this->editingQuestionId);
                $updateQuestion->handle($question, $attributes);
                $message = __('Registration question updated successfully.');
            } else {
                $createQuestion->handle($this->questionTarget($event), $attributes);
                $message = __('Registration question added successfully.');
            }
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            report($throwable);
            $message = $throwable instanceof \InvalidArgumentException
                ? $throwable->getMessage()
                : __('The registration question could not be saved. Please try again.');
            $this->errorToast($message);

            return;
        }

        $this->closeQuestionForm();
        $this->successToast($message);
    }

    public function archiveQuestion(
        string $questionId,
        ArchiveEventRegistrationQuestionAction $archiveQuestion,
    ): void {
        $event = $this->selectedEvent();
        $this->authorizeEvent($event);

        try {
            $question = $this->questionForEvent($event, $questionId);
            $archiveQuestion->handle($question);
            $this->successToast(__('Question archived. Existing registrations keep their recorded answers.'));
        } catch (Throwable $throwable) {
            report($throwable);
            $this->errorToast(__('The registration question could not be archived. Please try again.'));
        }
    }

    public function render(): View
    {
        $event = $this->selectedEvent();

        return view('livewire.pages.dashboard.events.registration-questions', [
            'event' => $event,
            'questions' => $this->questions($event),
            'questionTypes' => EventRegistrationQuestionType::options(),
            'scopeOptions' => [
                'event' => __('Entire event'),
                'occurrence' => __('One occurrence / date'),
                'session' => __('One session'),
            ],
            'occurrenceOptions' => $this->occurrenceOptions($event),
            'sessionOptions' => $this->sessionOptions($event),
        ]);
    }

    /** @return array<string, array<int, mixed>> */
    private function questionRules(): array
    {
        return [
            'questionForm.scope' => ['required', Rule::in(['event', 'occurrence', 'session'])],
            'questionForm.occurrence_id' => ['nullable', 'uuid'],
            'questionForm.session_id' => ['nullable', 'uuid'],
            'questionForm.question' => ['required', 'string', 'max:500'],
            'questionForm.description' => ['nullable', 'string', 'max:2000'],
            'questionForm.type' => ['required', Rule::in(array_keys(EventRegistrationQuestionType::options()))],
            'questionForm.options' => ['nullable', 'string', 'max:5000'],
            'questionForm.is_required' => ['boolean'],
        ];
    }

    /** @return array<string, mixed> */
    private function defaultQuestionForm(): array
    {
        return [
            'scope' => 'event',
            'occurrence_id' => '',
            'session_id' => '',
            'question' => '',
            'description' => '',
            'type' => EventRegistrationQuestionType::Text->value,
            'options' => '',
            'is_required' => false,
        ];
    }

    /** @return Collection<int, EventRegistrationQuestion> */
    private function questions(Event $event): Collection
    {
        return EventRegistrationQuestion::query()
            ->where('event_id', $event->getKey())
            ->ordered()
            ->get();
    }

    /** @return array<string, string> */
    private function occurrenceOptions(?Event $event = null): array
    {
        $event ??= $this->selectedEvent();

        return $event->occurrences()
            ->orderBy('starts_at')
            ->orderBy('created_at')
            ->get()
            ->mapWithKeys(function (EventOccurrence $occurrence): array {
                $label = (string) $occurrence->title;
                $date = UserDateTimeFormatter::translatedFormat($occurrence->starts_at, 'j M Y, h:i A');

                return [(string) $occurrence->getKey() => $date !== '' ? $label.' · '.$date : $label];
            })
            ->all();
    }

    /** @return array<string, string> */
    private function sessionOptions(Event $event): array
    {
        $occurrenceId = (string) ($this->questionForm['occurrence_id'] ?? '');

        if ($occurrenceId === '') {
            return [];
        }

        return EventSession::query()
            ->where('event_id', $event->getKey())
            ->where('event_occurrence_id', $occurrenceId)
            ->orderBy('starts_at')
            ->orderBy('created_at')
            ->get()
            ->mapWithKeys(function (EventSession $session): array {
                $label = (string) $session->title;
                $time = UserDateTimeFormatter::format($session->starts_at, 'h:i A');

                return [(string) $session->getKey() => $time !== '' ? $label.' · '.$time : $label];
            })
            ->all();
    }

    private function questionTarget(Event $event): Model
    {
        $scope = $this->normalizeScope($this->questionForm['scope'] ?? 'event');

        if ($scope === 'event') {
            return $event;
        }

        $occurrenceId = (string) ($this->questionForm['occurrence_id'] ?? '');

        if ($occurrenceId === '') {
            throw ValidationException::withMessages([
                'questionForm.occurrence_id' => __('Choose an occurrence first.'),
            ]);
        }

        $occurrence = $event->occurrences()->whereKey($occurrenceId)->firstOrFail();

        if ($scope === 'occurrence') {
            return $occurrence;
        }

        $sessionId = (string) ($this->questionForm['session_id'] ?? '');

        if ($sessionId === '') {
            throw ValidationException::withMessages([
                'questionForm.session_id' => __('Choose a session first.'),
            ]);
        }

        return $occurrence->sessions()->whereKey($sessionId)->firstOrFail();
    }

    private function questionForEvent(Event $event, string $questionId): EventRegistrationQuestion
    {
        /** @var EventRegistrationQuestion $question */
        $question = EventRegistrationQuestion::query()
            ->where('event_id', $event->getKey())
            ->whereKey($questionId)
            ->firstOrFail();

        return $question;
    }

    /** @return list<string> */
    private function parseOptions(string $value): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (string $option): string => mb_trim($option), preg_split('/\R/', $value) ?: []),
            static fn (string $option): bool => $option !== '',
        )));
    }

    private function normalizeScope(mixed $value): string
    {
        $scope = is_string($value) ? $value : 'event';

        return in_array($scope, ['event', 'occurrence', 'session'], true) ? $scope : 'event';
    }

    private function authorizeEvent(Event $event): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        abort_unless($user->can('update', $event), 403);
    }

    private function selectedEvent(): Event
    {
        /** @var Event $event */
        $event = Event::query()->whereKey($this->eventId)->firstOrFail();

        return $event;
    }
}
