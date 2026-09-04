<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Events;

use AIArmada\Checkout\Contracts\CheckoutServiceInterface;
use AIArmada\Checkout\Models\CheckoutSession;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Actions\RegisterForFreeAction;
use AIArmada\Events\Contracts\EventRegistrationQuestionResolver;
use AIArmada\Events\Enums\EventRegistrationQuestionType;
use AIArmada\Events\Models\EventRegistrationQuestion;
use AIArmada\Events\Support\EventTicketScope;
use AIArmada\Ticketing\Models\TicketType;
use App\Actions\Events\PrepareEventCheckoutAction;
use App\Models\Event;
use App\Models\User;
use App\Support\Commerce\EventCommerceModes;
use App\Support\Events\EventTicketingPolicy;
use App\Support\Events\ParticipantIdentity;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

#[Layout('layouts.app')]
#[Title('Event Checkout')]
final class Checkout extends Component
{
    public Event $event;

    #[Locked]
    public string $eventId;

    #[Locked]
    public ?string $ticketId = null;

    #[Locked]
    public string $checkoutAttemptKey = '';

    public ?TicketType $ticketType = null;

    public int $quantity = 1;

    /** @var array<int, array{name: string, email: string, phone: string, identity_document: string, is_purchaser: bool, answers: array<string, mixed>}> */
    public array $participants = [];

    public bool $agreementAccepted = false;

    public string $discountCode = '';

    public bool $processing = false;

    public function mount(Event $event, EventTicketingPolicy $ticketingPolicy, ?string $ticket = null): void
    {
        $this->event = $event;
        $this->eventId = (string) $event->getKey();
        $this->ticketId = $ticket;
        $this->checkoutAttemptKey = (string) str()->uuid();

        if (! $event->isRegistrationAvailable()) {
            abort(404);
        }

        if ($ticket !== null) {
            $ticketType = TicketType::query()
                ->with('ticketable')
                ->find($ticket);

            if (! $ticketType instanceof TicketType) {
                abort(404);
            }

            $ticketEvent = EventTicketScope::event($ticketType);

            if ($ticketEvent === null || $ticketEvent->isNot($event)) {
                abort(404);
            }

            if ($ticketType->status !== 'active' || ! $ticketType->isPubliclyVisible()) {
                abort(404);
            }

            if ((int) ($ticketType->price ?? 0) > 0 && ! EventCommerceModes::publicPaidCheckoutEnabled()) {
                abort(404);
            }

            $this->ticketType = $ticketType;
        } elseif ($ticketingPolicy->requiresTicketSelection($event) || ! $event->effectivePricingMode()->isFreeOnly()) {
            // A mixed or paid event must identify the actual ticket scope. This
            // avoids silently turning a paid event into an open RSVP form.
            abort(404);
        }

        $this->quantity = $this->minimumQuantity();
        $this->syncParticipants();
    }

    /** @return array<string, array<int, mixed>> */
    protected function rules(): array
    {
        $rules = [
            'quantity' => ['required', 'integer', 'min:'.$this->minimumQuantity(), 'max:'.$this->maximumQuantity()],
            'participants' => ['required', 'array', 'size:'.(int) $this->quantity],
            'participants.*.name' => ['required', 'string', 'max:150'],
            'participants.*.email' => ['nullable', 'email', 'max:255'],
            'participants.*.phone' => ['nullable', 'string', 'max:40'],
            'participants.*.identity_document' => ['nullable', 'string', 'max:100'],
            'participants.*.is_purchaser' => ['nullable', 'boolean'],
            'agreementAccepted' => ['accepted'],
            'discountCode' => ['nullable', 'string', 'max:80'],
        ];

        if ($this->participantIdentityType() !== 'none') {
            $rules['participants.*.identity_document'][] = 'required';
        }

        foreach ($this->registrationQuestions() as $question) {
            $answerPath = 'participants.*.answers.'.$question->field_key;
            $rules[$answerPath] = $this->questionRules($question);

            if ($question->type === EventRegistrationQuestionType::Multiselect) {
                $rules[$answerPath.'.*'] = [Rule::in($question->options ?? [])];
            }
        }

        return $rules;
    }

    public function updatedQuantity(mixed $value): void
    {
        $quantity = is_numeric($value) ? (int) $value : $this->minimumQuantity();
        $this->quantity = max($this->minimumQuantity(), min($this->maximumQuantity(), $quantity));
        $this->syncParticipants();
    }

    public function addParticipant(): void
    {
        if ($this->quantity >= $this->maximumQuantity()) {
            return;
        }

        $this->quantity++;
        $this->syncParticipants();
    }

    public function removeParticipant(): void
    {
        if ($this->quantity <= $this->minimumQuantity()) {
            return;
        }

        $this->quantity--;
        $this->syncParticipants();
    }

    public function submit(
        PrepareEventCheckoutAction $prepareCheckout,
        RegisterForFreeAction $registerForFree,
        CheckoutServiceInterface $checkoutService,
        EventTicketingPolicy $ticketingPolicy,
    ): void {
        if ($this->processing) {
            return;
        }

        $user = auth()->user();

        if (! $user instanceof User || ! $user->hasVerifiedEmail()) {
            $this->addError('checkout', __('Please verify your email address before continuing.'));

            return;
        }

        try {
            $this->validate();
            $this->processing = true;

            $event = Event::query()->findOrFail($this->eventId);
            $ticket = $this->resolveTicketForSubmit($event, $ticketingPolicy);
            $agreement = $this->agreementPayload($user);
            $participants = $this->normalizeParticipants($user, $agreement);

            Cache::lock('event-checkout:'.$this->checkoutAttemptKey, 120)->block(10, function () use (
                $agreement,
                $checkoutService,
                $event,
                $participants,
                $prepareCheckout,
                $registerForFree,
                $ticket,
                $user,
            ): void {
                if ($ticket === null) {
                    $registrations = OwnerContext::withOwner(null, fn () => $registerForFree->execute(
                        target: $event,
                        participants: $participants,
                        registrant: $user,
                        options: [
                            'with_pass' => true,
                            'idempotency_key' => $this->checkoutAttemptKey,
                        ],
                    ));

                    $batchId = (string) str()->uuid();

                    foreach ($registrations as $registration) {
                        $metadata = is_array($registration->metadata) ? $registration->metadata : [];
                        $registration->forceFill([
                            'metadata' => array_replace_recursive($metadata, [
                                'event_checkout' => [
                                    'batch_id' => $batchId,
                                    'agreement' => $agreement,
                                ],
                            ]),
                        ])->save();
                    }

                    $firstRegistration = $registrations->first();

                    if ($firstRegistration === null) {
                        throw new \RuntimeException('No registration was created.');
                    }

                    $this->redirectRoute('checkout.free', ['registration' => $firstRegistration->getKey()]);

                    return;
                }

                $session = OwnerContext::withOwner(null, fn (): CheckoutSession => $prepareCheckout->handle(
                    event: $event,
                    ticketType: $ticket,
                    quantity: $this->quantity,
                    participants: $participants,
                    buyer: $user,
                    agreement: $agreement,
                    discountCode: $this->discountCode,
                    idempotencyKey: $this->checkoutAttemptKey,
                ));

                // Replayed submissions reuse the durable session and must not
                // submit a second provider payment. A still-pending session is
                // the only safe one to enter into processing.
                if (! $session->wasRecentlyCreated && $session->status->name() !== 'pending') {
                    if ($session->payment_redirect_url !== null) {
                        $this->redirect($session->payment_redirect_url, navigate: false);
                    } elseif ($session->getKey() !== null) {
                        $this->redirectRoute('checkout.result', ['session' => $session->getKey()]);
                    }

                    return;
                }

                $result = OwnerContext::withOwner(null, fn () => $checkoutService->processCheckout($session));

                if ($result->requiresRedirect() && $result->redirectUrl !== null) {
                    $this->redirect($result->redirectUrl, navigate: false);

                    return;
                }

                if ($result->success && $result->sessionId !== null) {
                    $this->redirectRoute('checkout.result', ['session' => $result->sessionId]);

                    return;
                }

                $this->addError('checkout', $result->message ?? __('Payment could not be completed.'));
            });
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if ($exception instanceof \InvalidArgumentException) {
                $this->addError('checkout', $exception->getMessage());
            } else {
                report($exception);
                $this->addError('checkout', __('We could not complete this checkout. Please try again.'));
            }
        } finally {
            $this->processing = false;
        }
    }

    public function render(): View
    {
        return view('livewire.pages.events.checkout', [
            'ticket' => $this->ticketType,
            'minimumQuantity' => $this->minimumQuantity(),
            'maximumQuantity' => $this->maximumQuantity(),
            'isDirectFreeRegistration' => $this->ticketType === null,
            'ticketPrice' => $this->ticketType instanceof TicketType ? (int) ($this->ticketType->price ?? 0) : 0,
            'participantIdentityType' => $this->participantIdentityType(),
            'participantIdentityLabel' => $this->participantIdentityLabel(),
            'registrationQuestions' => $this->registrationQuestions(),
        ]);
    }

    private function minimumQuantity(): int
    {
        $ticketMinimum = $this->ticketType instanceof TicketType
            ? (int) ($this->ticketType->min_quantity ?? 1)
            : 1;

        return max(1, $ticketMinimum);
    }

    private function maximumQuantity(): int
    {
        $configuredMaximum = (int) config('events.features.commerce.checkout.max_participants', 10);
        $ticketMaximum = $this->ticketType instanceof TicketType
            ? (int) ($this->ticketType->max_quantity ?? 0)
            : 0;

        if ($configuredMaximum <= 0 && $ticketMaximum <= 0) {
            return 10;
        }

        if ($configuredMaximum <= 0) {
            return max($this->minimumQuantity(), $ticketMaximum);
        }

        if ($ticketMaximum <= 0) {
            return max($this->minimumQuantity(), $configuredMaximum);
        }

        return max($this->minimumQuantity(), min($configuredMaximum, $ticketMaximum));
    }

    private function syncParticipants(): void
    {
        $participants = array_values($this->participants);
        $questions = $this->registrationQuestions();

        $participants = array_slice($participants, 0, $this->quantity);

        while (count($participants) < $this->quantity) {
            $participants[] = [
                'name' => '',
                'email' => '',
                'phone' => '',
                'identity_document' => '',
                'is_purchaser' => count($participants) === 0,
                'answers' => [],
            ];
        }

        foreach ($participants as $index => $participant) {
            $answers = is_array($participant['answers'] ?? null) ? $participant['answers'] : [];

            $participants[$index] = [
                'name' => is_string($participant['name'] ?? null) ? $participant['name'] : '',
                'email' => is_string($participant['email'] ?? null) ? $participant['email'] : '',
                'phone' => is_string($participant['phone'] ?? null) ? $participant['phone'] : '',
                'identity_document' => is_string($participant['identity_document'] ?? null) ? $participant['identity_document'] : '',
                'is_purchaser' => (bool) ($participant['is_purchaser'] ?? false),
                'answers' => $this->normalizeAnswerState($answers, $questions),
            ];
        }

        if (! collect($participants)->contains(fn (array $participant): bool => $participant['is_purchaser'])) {
            $participants[0]['is_purchaser'] = true;
        }

        $this->participants = $participants;
    }

    private function resolveTicketForSubmit(Event $event, EventTicketingPolicy $ticketingPolicy): ?TicketType
    {
        if ($this->ticketId === null) {
            if ($ticketingPolicy->requiresTicketSelection($event) || ! $event->effectivePricingMode()->isFreeOnly()) {
                throw new \InvalidArgumentException('Choose a ticket before continuing.');
            }

            return null;
        }

        $ticket = TicketType::query()->with('ticketable')->find($this->ticketId);

        if (! $ticket instanceof TicketType) {
            throw new \InvalidArgumentException('The selected ticket is no longer available.');
        }

        $ticketEvent = EventTicketScope::event($ticket);

        if ($ticketEvent === null || $ticketEvent->isNot($event)) {
            throw new \InvalidArgumentException('The selected ticket does not belong to this event.');
        }

        if ($ticket->status !== 'active' || ! $ticket->isPubliclyVisible()) {
            throw new \InvalidArgumentException('The selected ticket is no longer available.');
        }

        if ((int) ($ticket->price ?? 0) > 0 && ! EventCommerceModes::publicPaidCheckoutEnabled()) {
            throw new \InvalidArgumentException('Online ticket payment is not available for this event yet.');
        }

        $this->ticketType = $ticket;

        return $ticket;
    }

    /**
     * @return array<string, mixed>
     */
    private function agreementPayload(User $user): array
    {
        return [
            'version' => 'event-participation-v1',
            'accepted_at' => now()->toIso8601String(),
            'accepted_by_type' => $user->getMorphClass(),
            'accepted_by_id' => (string) $user->getKey(),
        ];
    }

    /**
     * @param  array<string, mixed>  $agreement
     * @return array<int, array<string, mixed>>
     */
    private function normalizeParticipants(User $user, array $agreement): array
    {
        $participants = [];
        $purchaserAssigned = false;
        $questions = $this->registrationQuestions();

        foreach ($this->participants as $index => $participant) {
            $isPurchaser = (bool) ($participant['is_purchaser'] ?? false) && ! $purchaserAssigned;

            if ($isPurchaser) {
                $purchaserAssigned = true;
            }

            $data = [
                'name' => trim((string) ($participant['name'] ?? '')),
                'is_primary' => $index === 0,
                'is_purchaser' => $isPurchaser,
                'metadata' => ['event_checkout' => ['agreement' => $agreement]],
            ];

            $identityDocument = trim((string) ($participant['identity_document'] ?? ''));

            if ($identityDocument !== '' && $this->participantIdentityType() !== 'none') {
                $data['metadata']['event_checkout']['identity_document'] = ParticipantIdentity::protect(
                    $this->participantIdentityType(),
                    $identityDocument,
                );
            }

            foreach (['email', 'phone'] as $contactField) {
                $value = trim((string) ($participant[$contactField] ?? ''));

                if ($value !== '') {
                    $data[$contactField] = $contactField === 'email' ? mb_strtolower($value) : $value;
                }
            }

            $answers = $this->normalizeParticipantAnswers($participant, $questions);

            if ($answers !== []) {
                $data['answers'] = $answers;
            }

            if ($isPurchaser) {
                $data['participant_type'] = $user->getMorphClass();
                $data['participant_id'] = (string) $user->getKey();
            }

            $participants[] = $data;
        }

        // Never allow a tampered Livewire payload to create a checkout with
        // no purchaser. The verified account remains the account owner even
        // when the buyer entered another participant first.
        if (! $purchaserAssigned && isset($participants[0])) {
            $participants[0]['is_purchaser'] = true;
            $participants[0]['participant_type'] = $user->getMorphClass();
            $participants[0]['participant_id'] = (string) $user->getKey();
        }

        return $participants;
    }

    /**
     * @param  array<string, mixed>  $answers
     * @param  Collection<int, EventRegistrationQuestion>  $questions
     * @return array<string, mixed>
     */
    private function normalizeAnswerState(array $answers, Collection $questions): array
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
     * @param  Collection<int, EventRegistrationQuestion>  $questions
     * @return array<int, array<string, mixed>>
     */
    private function normalizeParticipantAnswers(array $participant, Collection $questions): array
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

    /**
     * @return array<int, mixed>
     */
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

    /**
     * @return Collection<int, EventRegistrationQuestion>
     */
    private function registrationQuestions(): Collection
    {
        return app(EventRegistrationQuestionResolver::class)->resolve($this->registrationTarget());
    }

    private function registrationTarget(): Model
    {
        // The ticket model is display state and can be stale after Livewire
        // hydration. Resolve the target from the locked ticket id so the
        // question rules always match the ticket that will be submitted.
        $ticket = $this->ticketId !== null
            ? TicketType::query()->with('ticketable')->find($this->ticketId)
            : null;

        $target = $ticket instanceof TicketType
            ? EventTicketScope::target($ticket)
            : null;

        return $target instanceof Model ? $target : $this->event;
    }

    private function participantIdentityType(): string
    {
        $settings = is_array($this->event->metadata) ? data_get($this->event->metadata, 'registration', []) : [];
        $type = is_array($settings) ? (string) ($settings['participant_identity'] ?? 'none') : 'none';

        return in_array($type, ['none', 'ic', 'passport'], true) ? $type : 'none';
    }

    private function participantIdentityLabel(): string
    {
        return match ($this->participantIdentityType()) {
            'ic' => __('IC number'),
            'passport' => __('Passport number'),
            default => '',
        };
    }
}
