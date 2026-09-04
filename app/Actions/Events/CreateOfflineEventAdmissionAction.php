<?php

declare(strict_types=1);

namespace App\Actions\Events;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Actions\CreateRegistrationsFromOrderAction;
use AIArmada\Events\Actions\IssueEventRegistrationPassesAction;
use AIArmada\Events\Support\EventTicketScope;
use AIArmada\Orders\Contracts\OrderServiceInterface;
use AIArmada\Orders\Models\Order;
use AIArmada\Ticketing\Models\Pass;
use AIArmada\Ticketing\Models\TicketType;
use App\Enums\OfflineAdmissionPaymentMethod;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

final class CreateOfflineEventAdmissionAction
{
    use AsAction;

    public function __construct(
        private readonly OrderServiceInterface $orders,
        private readonly CreateRegistrationsFromOrderAction $registrations,
        private readonly IssueEventRegistrationPassesAction $issuePasses,
    ) {}

    /**
     * Create one organizer-issued admission using the shared Commerce order
     * and event-registration pipeline.
     *
     * @param  array<string, mixed>  $participant
     * @return array{order: Order, registration: Registration, passes: Collection<int, Pass>}
     */
    public function handle(
        Event $event,
        TicketType $ticketType,
        array $participant,
        User $actor,
        string $paymentState = 'confirmed',
        string $paymentMethod = 'cash',
        ?string $paymentReference = null,
        ?string $notes = null,
    ): array {
        Gate::forUser($actor)->authorize('manageAdmissions', $event);

        $method = OfflineAdmissionPaymentMethod::tryFrom($paymentMethod);

        if (! $method instanceof OfflineAdmissionPaymentMethod) {
            throw new InvalidArgumentException('Choose a valid offline payment method.');
        }

        if (! in_array($paymentState, ['pending', 'confirmed'], true)) {
            throw new InvalidArgumentException('Choose a valid offline payment state.');
        }

        if ($method === OfflineAdmissionPaymentMethod::Complimentary && $paymentState !== 'confirmed') {
            throw new InvalidArgumentException('A complimentary admission is confirmed immediately.');
        }

        $ticketType->loadMissing('ticketable');
        $ticketEvent = EventTicketScope::event($ticketType);

        if ($ticketEvent === null || (string) $ticketEvent->getKey() !== (string) $event->getKey()) {
            throw new InvalidArgumentException('The selected ticket does not belong to this event.');
        }

        if ((string) $ticketType->status !== 'active') {
            throw new InvalidArgumentException('The selected ticket is not active.');
        }

        $target = EventTicketScope::target($ticketType);

        if (! $target instanceof Model) {
            throw new InvalidArgumentException('The selected ticket does not have a valid event scope.');
        }

        $participant = $this->normalizeParticipant($participant);
        $basePrice = max(0, (int) ($ticketType->price ?? 0));

        if ($basePrice === 0 && $paymentState === 'pending') {
            throw new InvalidArgumentException('A free admission does not need a pending payment.');
        }

        if ($basePrice === 0 && $method !== OfflineAdmissionPaymentMethod::Complimentary) {
            throw new InvalidArgumentException('Free admissions must use the complimentary payment method.');
        }

        $isComplimentary = $method === OfflineAdmissionPaymentMethod::Complimentary;
        $unitPrice = $isComplimentary ? 0 : $basePrice;
        $discountAmount = $isComplimentary ? $basePrice : 0;
        $currency = mb_strtoupper((string) ($ticketType->currency ?: config('events.defaults.currency', 'MYR')));
        $reference = $this->normalizeReference($paymentReference);
        $intakeId = (string) str()->uuid();
        // Create the admission pending first. The shared OrderPaid event is
        // the source of truth that confirms it after the order payment is
        // committed, including for complimentary admissions.
        $registrationStatus = 'pending';
        $registrationPaymentStatus = 'pending';
        $offlineMetadata = [
            'event_id' => (string) $event->getKey(),
            'ticket_type_id' => (string) $ticketType->getKey(),
            'recorded_by_type' => $actor->getMorphClass(),
            'recorded_by_id' => (string) $actor->getKey(),
            'payment_method' => $method->value,
            'payment_state' => $paymentState,
            'payment_reference' => $reference,
        ];
        $orderMetadata = [
            'event_id' => (string) $event->getKey(),
            'event_fulfillment' => 'event_registration',
            'offline_admission' => $offlineMetadata,
        ];

        $result = OwnerContext::withOwner(null, fn (): array => DB::transaction(function () use (
            $actor,
            $basePrice,
            $currency,
            $discountAmount,
            $event,
            $intakeId,
            $method,
            $notes,
            $offlineMetadata,
            $orderMetadata,
            $paymentState,
            $participant,
            $reference,
            $registrationPaymentStatus,
            $registrationStatus,
            $target,
            $ticketType,
            $unitPrice,
        ): array {
            $order = $this->orders->createOrder(
                orderData: [
                    'customer_id' => (string) $actor->getKey(),
                    'customer_type' => $actor->getMorphClass(),
                    'subtotal' => $basePrice,
                    'discount_total' => $discountAmount,
                    'shipping_total' => 0,
                    'tax_total' => 0,
                    'grand_total' => $unitPrice,
                    'currency' => $currency,
                    'notes' => $notes,
                    'metadata' => $orderMetadata,
                ],
                items: [[
                    'purchasable_id' => $ticketType->getKey(),
                    'purchasable_type' => $ticketType->getMorphClass(),
                    'name' => (string) $ticketType->name,
                    'sku' => (string) $ticketType->code,
                    'quantity' => 1,
                    'unit_price' => $basePrice,
                    'discount_amount' => $discountAmount,
                    'tax_amount' => 0,
                    'currency' => $currency,
                    'options' => [
                        'event_fulfillment' => 'event_registration',
                        'event_id' => (string) $event->getKey(),
                        'event_occurrence_id' => EventTicketScope::occurrence($ticketType)?->getKey(),
                        'event_session_id' => EventTicketScope::session($ticketType)?->getKey(),
                        'participants' => [$participant],
                    ],
                    'metadata' => [
                        'offline_admission' => $offlineMetadata,
                    ],
                ]],
                intakeSource: 'offline_event_admission',
                intakeId: $intakeId,
            );

            $orderItem = $order->items->first();

            if ($orderItem === null) {
                throw new InvalidArgumentException('The offline admission order has no item.');
            }

            $registrations = $this->registrations->handle(
                target: $target,
                orderItem: $orderItem,
                participants: [$participant],
                purchaser: $actor,
                options: [
                    'registration_status' => $registrationStatus,
                    'item_status' => $registrationStatus,
                    'source' => 'offline_admission',
                    'payment_status' => $registrationPaymentStatus,
                    'metadata' => [
                        'offline_admission' => $offlineMetadata,
                    ],
                    'notes' => $notes,
                ],
            );
            $registration = $registrations->first();

            if (! $registration instanceof Registration) {
                throw new InvalidArgumentException('The offline admission registration could not be created.');
            }

            $passes = new Collection;

            if ($paymentState === 'confirmed') {
                $order = $this->orders->confirmPayment(
                    order: $order,
                    transactionId: $reference,
                    gateway: $method->gateway(),
                    amount: $unitPrice,
                    metadata: [
                        'offline_admission' => $offlineMetadata,
                        'recorded_by_type' => $actor->getMorphClass(),
                        'recorded_by_id' => (string) $actor->getKey(),
                    ],
                );
            }

            return [
                'order' => $order->fresh(['items', 'payments']),
                'registration' => $registration->fresh(['participants', 'items', 'passes']),
                'passes' => $passes,
            ];
        }));

        /** @var array{order: Order, registration: Registration, passes: Collection<int, Pass>} $result */
        if ($paymentState === 'confirmed') {
            $registration = $result['registration']->fresh(['participants', 'items', 'passes']);

            if (! $registration instanceof Registration) {
                throw new InvalidArgumentException('The offline admission registration could not be refreshed.');
            }

            $result['passes'] = $this->issuePasses->handle($registration);
            $result['registration'] = $registration->fresh(['participants', 'items', 'passes']);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $participant
     * @return array<string, mixed>
     */
    private function normalizeParticipant(array $participant): array
    {
        $name = trim((string) ($participant['name'] ?? ''));

        if ($name === '') {
            throw new InvalidArgumentException('A participant name is required.');
        }

        $normalized = [
            'name' => mb_substr($name, 0, 150),
            'is_primary' => true,
            'is_purchaser' => (bool) ($participant['is_purchaser'] ?? false),
        ];

        foreach (['email', 'phone', 'participant_type', 'participant_id', 'relationship_to_registrant'] as $key) {
            $value = $participant[$key] ?? null;

            if (is_scalar($value) && trim((string) $value) !== '') {
                $normalized[$key] = $key === 'email'
                    ? mb_strtolower(trim((string) $value))
                    : trim((string) $value);
            }
        }

        if (is_array($participant['metadata'] ?? null)) {
            $normalized['metadata'] = $participant['metadata'];
        }

        if (is_array($participant['answers'] ?? null) && $participant['answers'] !== []) {
            $normalized['answers'] = array_values(array_filter(
                $participant['answers'],
                static fn (mixed $answer): bool => is_array($answer),
            ));
        }

        return $normalized;
    }

    private function normalizeReference(?string $reference): string
    {
        $reference = trim((string) $reference);

        return $reference !== '' ? mb_substr($reference, 0, 120) : 'offline-'.str()->uuid();
    }
}
