<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Dashboard\Events;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Contracts\EventRegistrationQuestionResolver;
use AIArmada\Events\Enums\EventRegistrationQuestionType;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventRegistrationQuestion;
use AIArmada\Events\Models\EventSession;
use AIArmada\Events\Support\EventTicketScope;
use AIArmada\Orders\Models\Order;
use AIArmada\Ticketing\Models\Pass;
use AIArmada\Ticketing\Models\TicketType;
use App\Actions\Events\ConfirmOfflineEventAdmissionAction;
use App\Actions\Events\CreateOfflineEventAdmissionAction;
use App\Enums\OfflineAdmissionPaymentMethod;
use App\Livewire\Concerns\InteractsWithToasts;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use App\Support\Events\ParticipantIdentity;
use App\Support\Timezone\UserDateTimeFormatter;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

#[Layout('layouts.app')]
#[Title('Offline admissions')]
final class OfflineAdmissions extends Component
{
    use InteractsWithToasts;

    #[Locked]
    public string $eventId = '';

    public string $ticketId = '';

    /** @var array<string, mixed> */
    public array $participant = [];

    public bool $agreementAccepted = false;

    public string $paymentState = 'confirmed';

    public string $paymentMethod = 'cash';

    public string $paymentReference = '';

    public string $notes = '';

    public bool $processing = false;

    public ?string $confirmingOrderId = null;

    public string $confirmationPaymentMethod = 'cash';

    public string $confirmationPaymentReference = '';

    public string $confirmationNotes = '';

    /** @var array<string, mixed>|null */
    public ?array $lastAdmission = null;

    public function boot(): void
    {
        OwnerContext::setForRequest(null);
    }

    public function mount(Event $event): void
    {
        $this->authorizeAdmissions($event);
        $this->eventId = (string) $event->getKey();
        $this->participant = $this->defaultParticipant();
        $this->ticketId = array_key_first($this->ticketOptions($event)) ?? '';
        $this->normalizePaymentMethodForTicket($event);
    }

    public function updatedTicketId(mixed $value): void
    {
        $this->ticketId = is_string($value) ? $value : '';
        $this->participant['answers'] = $this->normalizeAnswerState(
            is_array($this->participant['answers'] ?? null) ? $this->participant['answers'] : [],
            $this->registrationQuestions(),
        );
        $this->normalizePaymentMethodForTicket($this->selectedEvent());
        $this->resetValidation();
    }

    public function updatedPaymentState(mixed $value): void
    {
        $this->paymentState = is_string($value) && in_array($value, ['pending', 'confirmed'], true)
            ? $value
            : 'confirmed';

        if ($this->paymentState === 'pending' && $this->paymentMethod === OfflineAdmissionPaymentMethod::Complimentary->value) {
            $this->paymentMethod = OfflineAdmissionPaymentMethod::Cash->value;
        }
    }

    public function updatedPaymentMethod(mixed $value): void
    {
        $this->paymentMethod = is_string($value) && array_key_exists($value, $this->paymentMethodOptions())
            ? $value
            : OfflineAdmissionPaymentMethod::Cash->value;

        if ($this->paymentMethod === OfflineAdmissionPaymentMethod::Complimentary->value) {
            $this->paymentState = 'confirmed';
        }
    }

    public function submit(CreateOfflineEventAdmissionAction $createAdmission): void
    {
        if ($this->processing) {
            return;
        }

        $actor = auth()->user();

        if (! $actor instanceof User) {
            abort(403);
        }

        try {
            $this->authorizeAdmissions($this->selectedEvent());
            $this->validate($this->admissionRules());
            $this->processing = true;

            $event = $this->selectedEvent();
            $ticket = $this->ticketForEvent($event, $this->ticketId);

            if (! $ticket instanceof TicketType) {
                throw new \InvalidArgumentException(__('Choose an active ticket first.'));
            }

            $result = OwnerContext::withOwner(null, fn (): array => $createAdmission->handle(
                event: $event,
                ticketType: $ticket,
                participant: $this->normalizedParticipant($actor),
                actor: $actor,
                paymentState: $this->paymentState,
                paymentMethod: $this->paymentMethod,
                paymentReference: $this->nullableText($this->paymentReference),
                notes: $this->nullableText($this->notes),
            ));

            $this->lastAdmission = $this->admissionSummary(
                $result['order'],
                $result['registration'],
                $result['passes'],
            );
            $wasPending = $this->paymentState === 'pending';
            $this->resetAdmissionForm();
            $this->successToast($wasPending
                ? __('Admission recorded as pending. Confirm the payment when it is received.')
                : __('Admission recorded and pass issued.'));
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if ($exception instanceof \InvalidArgumentException) {
                $this->errorToast($exception->getMessage());
            } else {
                report($exception);
                $this->errorToast(__('The admission could not be recorded. Please check the details and try again.'));
            }
        } finally {
            $this->processing = false;
        }
    }

    public function startConfirmation(string $orderId): void
    {
        $event = $this->selectedEvent();
        $this->authorizeAdmissions($event);
        $this->confirmingOrderId = $orderId;
        $this->confirmationPaymentMethod = OfflineAdmissionPaymentMethod::Cash->value;
        $this->confirmationPaymentReference = '';
        $this->confirmationNotes = '';
        $this->resetValidation();
    }

    public function cancelConfirmation(): void
    {
        $this->confirmingOrderId = null;
        $this->confirmationPaymentReference = '';
        $this->confirmationNotes = '';
        $this->resetValidation();
    }

    public function confirmPendingAdmission(
        ConfirmOfflineEventAdmissionAction $confirmAdmission,
    ): void {
        if ($this->processing || $this->confirmingOrderId === null) {
            return;
        }

        $actor = auth()->user();

        if (! $actor instanceof User) {
            abort(403);
        }

        try {
            $event = $this->selectedEvent();
            $this->authorizeAdmissions($event);
            $this->validate($this->confirmationRules());
            $this->processing = true;

            $order = OwnerContext::withOwner(null, fn (): Order => Order::query()->findOrFail($this->confirmingOrderId));
            $result = OwnerContext::withOwner(null, fn (): array => $confirmAdmission->handle(
                event: $event,
                order: $order,
                actor: $actor,
                paymentMethod: $this->confirmationPaymentMethod,
                paymentReference: $this->nullableText($this->confirmationPaymentReference),
                notes: $this->nullableText($this->confirmationNotes),
            ));

            $this->lastAdmission = $this->admissionSummary(
                $result['order'],
                $result['registration'],
                $result['passes'],
            );
            $this->cancelConfirmation();
            $this->successToast(__('Payment confirmed and pass issued.'));
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if ($exception instanceof \InvalidArgumentException) {
                $this->errorToast($exception->getMessage());
            } else {
                report($exception);
                $this->errorToast(__('The pending admission could not be confirmed. Please try again.'));
            }
        } finally {
            $this->processing = false;
        }
    }

    /** @return array<string, string> */
    public function paymentMethodOptions(): array
    {
        return collect(OfflineAdmissionPaymentMethod::cases())
            ->mapWithKeys(fn (OfflineAdmissionPaymentMethod $method): array => [$method->value => $method->getLabel()])
            ->all();
    }

    /**
     * @return array<string, array{label: string, price: int, currency: string, scope: string}>
     */
    public function ticketOptions(?Event $event = null): array
    {
        $event ??= $this->selectedEvent();

        /** @var Collection<int, TicketType> $tickets */
        $tickets = collect();
        $tickets = $tickets->merge($event->ticketTypes()->with('ticketable')->get());

        /** @var EloquentCollection<int, EventOccurrence> $occurrences */
        $occurrences = $event->occurrences()->get();

        foreach ($occurrences as $occurrence) {
            $tickets = $tickets->merge($occurrence->ticketTypes()->with('ticketable')->get());
        }

        /** @var EloquentCollection<int, EventSession> $sessions */
        $sessions = $event->sessions()->get();

        foreach ($sessions as $session) {
            $tickets = $tickets->merge($session->ticketTypes()->with('ticketable')->get());
        }

        return $tickets
            ->filter(fn (TicketType $ticket): bool => (string) $ticket->status === 'active')
            ->unique(fn (TicketType $ticket): string => (string) $ticket->getKey())
            ->sortBy(fn (TicketType $ticket): string => mb_strtolower((string) $ticket->name))
            ->mapWithKeys(function (TicketType $ticket): array {
                $target = EventTicketScope::target($ticket);

                return [(string) $ticket->getKey() => [
                    'label' => (string) $ticket->name,
                    'price' => (int) ($ticket->price ?? 0),
                    'currency' => mb_strtoupper((string) ($ticket->currency ?: config('events.defaults.currency', 'MYR'))),
                    'scope' => $this->ticketScopeLabel($target),
                ]];
            })
            ->all();
    }

    /** @return EloquentCollection<int, EventRegistrationQuestion> */
    public function registrationQuestions(): EloquentCollection
    {
        $target = $this->registrationTarget();

        /** @var EloquentCollection<int, EventRegistrationQuestion> $questions */
        $questions = app(EventRegistrationQuestionResolver::class)->resolve($target);

        return $questions;
    }

    /** @return Collection<int, array<string, mixed>> */
    public function recentAdmissions(): Collection
    {
        $registrations = Registration::query()
            ->where('event_id', $this->selectedEvent()->getKey())
            ->where('source', 'offline_admission')
            ->with(['participants', 'passes', 'items.ticketType'])
            ->orderByDesc('registered_at')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();
        $orderIds = $registrations->pluck('external_order_id')->filter()->map(static fn (mixed $id): string => (string) $id)->values();

        /** @var Collection<string, Order> $orders */
        $orders = OwnerContext::withOwner(null, function () use ($orderIds): Collection {
            return collect(Order::query()->whereIn('id', $orderIds->all())->get())
                ->keyBy(static fn (Order $order): string => (string) $order->getKey());
        });

        /** @var Collection<int, array<string, mixed>> $admissions */
        $admissions = collect();

        foreach ($registrations as $registration) {
            $participant = $registration->participants->firstWhere('is_primary', true)
                ?? $registration->participants->first();
            $order = $orders->get((string) $registration->external_order_id);
            $item = $registration->items->first();

            $admissions->push([
                'id' => (string) $registration->getKey(),
                'registration_no' => (string) $registration->registration_no,
                'name' => $participant?->name ?: __('Unnamed participant'),
                'ticket' => $item?->ticketType?->name ?: __('Event admission'),
                'status' => $registration->statusValue(),
                'payment_status' => (string) $registration->payment_status,
                'order_id' => $order?->getKey(),
                'order_number' => $order?->order_number,
                'pass_count' => $registration->passes->count(),
                'registered_at' => $registration->registered_at instanceof CarbonInterface
                    ? UserDateTimeFormatter::translatedFormat($registration->registered_at, 'j M Y, h:i A')
                    : __('Date to be confirmed'),
            ]);
        }

        return $admissions;
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'confirmed' => __('Confirmed'),
            'pending' => __('Pending payment'),
            'cancelled' => __('Cancelled'),
            'refunded' => __('Refunded'),
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    public function paymentStatusLabel(string $status): string
    {
        return match ($status) {
            'paid' => __('Paid'),
            'complimentary' => __('Complimentary'),
            'pending' => __('Pending'),
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    public function formatMoney(int $amount, string $currency): string
    {
        return number_format($amount / 100, 2).' '.mb_strtoupper($currency);
    }

    public function render(): View
    {
        $event = $this->selectedEvent();

        return view('livewire.pages.dashboard.events.offline-admissions', [
            'event' => $event,
            'ticketOptions' => $this->ticketOptions($event),
            'registrationQuestions' => $this->registrationQuestions(),
            'paymentMethods' => $this->paymentMethodOptions(),
            'recentAdmissions' => $this->recentAdmissions(),
            'participantIdentityType' => $this->participantIdentityType(),
            'participantIdentityLabel' => $this->participantIdentityLabel(),
        ]);
    }

    /** @return array<string, array<int, mixed>> */
    private function admissionRules(): array
    {
        $rules = [
            'ticketId' => ['required', 'uuid'],
            'participant.name' => ['required', 'string', 'max:150'],
            'participant.email' => ['nullable', 'email', 'max:255'],
            'participant.phone' => ['nullable', 'string', 'max:40'],
            'participant.identity_document' => ['nullable', 'string', 'max:100'],
            'participant.is_purchaser' => ['nullable', 'boolean'],
            'agreementAccepted' => ['accepted'],
            'paymentState' => ['required', Rule::in(['pending', 'confirmed'])],
            'paymentMethod' => ['required', Rule::in(array_keys($this->paymentMethodOptions()))],
            'paymentReference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];

        if ($this->participantIdentityType() !== 'none') {
            $rules['participant.identity_document'][] = 'required';
        }

        foreach ($this->registrationQuestions() as $question) {
            $answerPath = 'participant.answers.'.$question->field_key;
            $rules[$answerPath] = $this->questionRules($question);

            if ($question->type === EventRegistrationQuestionType::Multiselect) {
                $rules[$answerPath.'.*'] = [Rule::in($question->options ?? [])];
            }
        }

        return $rules;
    }

    /** @return array<string, array<int, mixed>> */
    private function confirmationRules(): array
    {
        return [
            'confirmationPaymentMethod' => [
                'required',
                Rule::in([
                    OfflineAdmissionPaymentMethod::Cash->value,
                    OfflineAdmissionPaymentMethod::BankTransfer->value,
                ]),
            ],
            'confirmationPaymentReference' => ['nullable', 'string', 'max:120'],
            'confirmationNotes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<int, mixed> */
    private function questionRules(EventRegistrationQuestion $question): array
    {
        $rules = $question->is_required
            ? [$question->type === EventRegistrationQuestionType::Checkbox ? 'accepted' : 'required']
            : ['nullable'];

        return match ($question->type) {
            EventRegistrationQuestionType::Text => [...$rules, 'string', 'max:500'],
            EventRegistrationQuestionType::Textarea => [...$rules, 'string', 'max:5000'],
            EventRegistrationQuestionType::Number => [...$rules, 'numeric'],
            EventRegistrationQuestionType::Date => [...$rules, 'date_format:Y-m-d'],
            EventRegistrationQuestionType::Select => [...$rules, 'string', Rule::in($question->options ?? [])],
            EventRegistrationQuestionType::Multiselect => [...$rules, 'array'],
            EventRegistrationQuestionType::Checkbox => $question->is_required ? $rules : [...$rules, 'boolean'],
        };
    }

    /** @return array<string, mixed> */
    private function defaultParticipant(): array
    {
        return [
            'name' => '',
            'email' => '',
            'phone' => '',
            'identity_document' => '',
            'is_purchaser' => false,
            'answers' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function normalizedParticipant(User $actor): array
    {
        $participant = [
            'name' => trim((string) ($this->participant['name'] ?? '')),
            'is_primary' => true,
            'is_purchaser' => (bool) ($this->participant['is_purchaser'] ?? false),
            'metadata' => [
                'event_checkout' => [
                    'agreement' => [
                        'version' => 'event-participation-v1',
                        'accepted_at' => now()->toIso8601String(),
                        'recorded_by_type' => $actor->getMorphClass(),
                        'recorded_by_id' => (string) $actor->getKey(),
                        'acceptance_mode' => 'organizer_recorded',
                    ],
                ],
            ],
        ];

        foreach (['email', 'phone'] as $contactField) {
            $value = trim((string) ($this->participant[$contactField] ?? ''));

            if ($value !== '') {
                $participant[$contactField] = $contactField === 'email' ? mb_strtolower($value) : $value;
            }
        }

        $identityDocument = trim((string) ($this->participant['identity_document'] ?? ''));

        if ($identityDocument !== '' && $this->participantIdentityType() !== 'none') {
            $participant['metadata']['event_checkout']['identity_document'] = ParticipantIdentity::protect(
                $this->participantIdentityType(),
                $identityDocument,
            );
        }

        $answers = $this->normalizeParticipantAnswers($this->participant, $this->registrationQuestions());

        if ($answers !== []) {
            $participant['answers'] = $answers;
        }

        return $participant;
    }

    /**
     * @param  array<string, mixed>  $answers
     * @param  EloquentCollection<int, EventRegistrationQuestion>  $questions
     * @return array<string, mixed>
     */
    private function normalizeAnswerState(array $answers, EloquentCollection $questions): array
    {
        $normalized = [];

        foreach ($questions as $question) {
            $key = (string) $question->field_key;

            if (! array_key_exists($key, $answers)) {
                $normalized[$key] = $question->type === EventRegistrationQuestionType::Multiselect
                    ? []
                    : ($question->type === EventRegistrationQuestionType::Checkbox ? false : '');

                continue;
            }

            $value = $answers[$key];
            $normalized[$key] = match ($question->type) {
                EventRegistrationQuestionType::Multiselect => is_array($value)
                    ? array_values(array_filter($value, is_scalar(...)))
                    : [],
                EventRegistrationQuestionType::Checkbox => (bool) $value,
                default => is_scalar($value) ? (string) $value : '',
            };
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $participant
     * @param  EloquentCollection<int, EventRegistrationQuestion>  $questions
     * @return array<int, array<string, mixed>>
     */
    private function normalizeParticipantAnswers(array $participant, EloquentCollection $questions): array
    {
        $rawAnswers = is_array($participant['answers'] ?? null) ? $participant['answers'] : [];
        $answers = [];

        foreach ($questions as $question) {
            $key = (string) $question->field_key;

            if (! array_key_exists($key, $rawAnswers)) {
                continue;
            }

            $value = $rawAnswers[$key];

            if ($value === null || $value === '' || (is_array($value) && $value === [])) {
                continue;
            }

            $answer = null;
            $answerJson = null;

            if ($question->type === EventRegistrationQuestionType::Multiselect) {
                $values = is_array($value) ? array_values(array_filter($value, is_scalar(...))) : [];
                $answerJson = array_map(static fn (mixed $item): string => (string) $item, $values);
                $answer = implode(', ', $answerJson);
            } elseif ($question->type === EventRegistrationQuestionType::Checkbox) {
                $answer = (bool) $value ? 'yes' : 'no';
            } elseif (is_scalar($value)) {
                $answer = (string) $value;
            }

            if ($answer === null) {
                continue;
            }

            $answers[] = [
                'field_key' => $key,
                'question' => (string) $question->question,
                'answer' => $answer,
                'answer_json' => $answerJson,
                'metadata' => [
                    'question_id' => (string) $question->getKey(),
                    'question_type' => $question->type->value,
                ],
            ];
        }

        return $answers;
    }

    private function registrationTarget(): Model
    {
        $ticket = $this->ticketForEvent($this->selectedEvent(), $this->ticketId, false);
        $target = $ticket instanceof TicketType ? EventTicketScope::target($ticket) : null;

        return $target instanceof Model ? $target : $this->selectedEvent();
    }

    private function ticketForEvent(Event $event, string $ticketId, bool $requireActive = true): ?TicketType
    {
        if ($ticketId === '') {
            return null;
        }

        $ticket = TicketType::query()->with('ticketable')->find($ticketId);

        if (! $ticket instanceof TicketType) {
            return null;
        }

        $ticketEvent = EventTicketScope::event($ticket);

        if ($ticketEvent === null || (string) $ticketEvent->getKey() !== (string) $event->getKey()) {
            return null;
        }

        if ($requireActive && (string) $ticket->status !== 'active') {
            return null;
        }

        return $ticket;
    }

    private function selectedEvent(): Event
    {
        /** @var Event $event */
        $event = Event::query()->whereKey($this->eventId)->firstOrFail();

        return $event;
    }

    private function authorizeAdmissions(Event $event): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        abort_unless($user->can('manageAdmissions', $event), 403);
    }

    private function ticketScopeLabel(?Model $target): string
    {
        return match (true) {
            $target instanceof EventSession => __('Session: :name', ['name' => $target->title]),
            $target instanceof EventOccurrence => __('Date: :name', ['name' => $target->title]),
            default => __('Entire event'),
        };
    }

    private function participantIdentityType(): string
    {
        $metadata = is_array($this->selectedEvent()->metadata) ? $this->selectedEvent()->metadata : [];
        $configured = data_get($metadata, 'registration.participant_identity', 'none');

        return in_array($configured, ['none', 'ic', 'passport'], true) ? (string) $configured : 'none';
    }

    private function participantIdentityLabel(): string
    {
        return match ($this->participantIdentityType()) {
            'ic' => __('IC number'),
            'passport' => __('Passport number'),
            default => '',
        };
    }

    /**
     * @param  Collection<int, Pass>  $passes
     * @return array<string, mixed>
     */
    private function admissionSummary(Order $order, Registration $registration, Collection $passes): array
    {
        $participant = $registration->participants()->orderByDesc('is_primary')->orderBy('created_at')->first();

        return [
            'order_id' => (string) $order->getKey(),
            'order_number' => (string) $order->order_number,
            'registration_no' => (string) $registration->registration_no,
            'name' => $participant?->name ?: __('Unnamed participant'),
            'status' => $registration->statusValue(),
            'payment_status' => (string) $registration->payment_status,
            'pass_count' => $passes->count(),
        ];
    }

    private function nullableText(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function resetAdmissionForm(): void
    {
        $this->participant = $this->defaultParticipant();
        $this->agreementAccepted = false;
        $this->paymentState = 'confirmed';
        $this->paymentMethod = OfflineAdmissionPaymentMethod::Cash->value;
        $this->paymentReference = '';
        $this->notes = '';
        $this->resetValidation();
    }

    private function normalizePaymentMethodForTicket(Event $event): void
    {
        $ticket = $this->ticketForEvent($event, $this->ticketId);

        if (! $ticket instanceof TicketType) {
            return;
        }

        if ((int) ($ticket->price ?? 0) === 0) {
            $this->paymentMethod = OfflineAdmissionPaymentMethod::Complimentary->value;
            $this->paymentState = 'confirmed';
        } elseif ($this->paymentMethod === OfflineAdmissionPaymentMethod::Complimentary->value) {
            $this->paymentMethod = OfflineAdmissionPaymentMethod::Cash->value;
        }
    }
}
