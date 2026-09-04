<?php

declare(strict_types=1);

namespace App\Actions\Events;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Actions\IssueEventRegistrationPassesAction;
use AIArmada\Events\Support\EventTicketScope;
use AIArmada\Orders\Contracts\OrderServiceInterface;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\States\PendingPayment;
use AIArmada\Ticketing\Models\Pass;
use AIArmada\Ticketing\Models\TicketType;
use App\Enums\OfflineAdmissionPaymentMethod;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

final class ConfirmOfflineEventAdmissionAction
{
    use AsAction;

    public function __construct(
        private readonly OrderServiceInterface $orders,
        private readonly IssueEventRegistrationPassesAction $issuePasses,
    ) {}

    /**
     * Confirm a provisional organizer-issued admission and issue its pass.
     *
     * @return array{order: Order, registration: Registration, passes: Collection<int, Pass>}
     */
    public function handle(
        Event $event,
        Order $order,
        User $actor,
        string $paymentMethod = 'cash',
        ?string $paymentReference = null,
        ?string $notes = null,
    ): array {
        Gate::forUser($actor)->authorize('manageAdmissions', $event);

        $method = OfflineAdmissionPaymentMethod::tryFrom($paymentMethod);

        if (! $method instanceof OfflineAdmissionPaymentMethod || $method === OfflineAdmissionPaymentMethod::Complimentary) {
            throw new InvalidArgumentException('Choose cash or bank transfer when confirming a pending admission.');
        }

        $result = OwnerContext::withOwner(null, fn (): array => DB::transaction(function () use (
            $actor,
            $event,
            $method,
            $notes,
            $order,
            $paymentReference,
        ): array {
            $order = Order::query()
                ->with(['items.purchasable', 'payments'])
                ->lockForUpdate()
                ->findOrFail($order->getKey());

            $orderMetadata = is_array($order->metadata) ? $order->metadata : [];

            if ((string) data_get($orderMetadata, 'event_id') !== (string) $event->getKey()) {
                throw new InvalidArgumentException('The selected order does not belong to this event.');
            }

            if ($order->isPaid()) {
                return $this->existingResult($order);
            }

            if (! $order->status instanceof PendingPayment) {
                throw new InvalidArgumentException('Only pending offline admissions can be confirmed.');
            }

            $orderItem = $order->items()->first();
            $ticketType = $orderItem?->purchasable;

            if (! $ticketType instanceof TicketType) {
                throw new InvalidArgumentException('The pending order does not reference a valid ticket.');
            }

            $ticketEvent = EventTicketScope::event($ticketType);

            if ($ticketEvent === null || (string) $ticketEvent->getKey() !== (string) $event->getKey()) {
                throw new InvalidArgumentException('The pending order ticket does not belong to this event.');
            }

            if ((string) $ticketType->status !== 'active') {
                throw new InvalidArgumentException('The ticket is no longer active and cannot be confirmed.');
            }

            $registrations = Registration::query()
                ->where('external_order_id', $order->getKey())
                ->where('external_order_type', Order::class)
                ->lockForUpdate()
                ->get();

            $registration = $registrations->first();

            if (! $registration instanceof Registration) {
                throw new InvalidArgumentException('The pending order has no event registration.');
            }

            $reference = $this->resolveReference($paymentReference, $orderMetadata, $order);
            $confirmedMetadata = array_replace_recursive($orderMetadata, [
                'offline_admission' => [
                    'payment_method' => $method->value,
                    'payment_state' => 'confirmed',
                    'payment_reference' => $reference,
                    'confirmed_by_type' => $actor->getMorphClass(),
                    'confirmed_by_id' => (string) $actor->getKey(),
                ],
            ]);

            $order->forceFill([
                'metadata' => $confirmedMetadata,
                'notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : $order->notes,
            ])->save();

            $order = $this->orders->confirmPayment(
                order: $order,
                transactionId: $reference,
                gateway: $method->gateway(),
                amount: (int) $order->grand_total,
                metadata: [
                    'offline_admission' => data_get($confirmedMetadata, 'offline_admission', []),
                ],
            );

            return [
                'order' => $order->fresh(['items', 'payments']),
                'registration' => $registration->fresh(['participants', 'items', 'passes']),
                'passes' => new Collection,
            ];
        }));

        /** @var array{order: Order, registration: Registration, passes: Collection<int, Pass>} $result */
        if ($result['order']->isPaid()) {
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
     * @return array{order: Order, registration: Registration, passes: Collection<int, Pass>}
     */
    private function existingResult(Order $order): array
    {
        $registration = Registration::query()
            ->where('external_order_id', $order->getKey())
            ->where('external_order_type', Order::class)
            ->with(['participants', 'items', 'passes'])
            ->first();

        if (! $registration instanceof Registration) {
            throw new InvalidArgumentException('The paid order has no event registration.');
        }

        /** @var Collection<int, Pass> $passes */
        $passes = $registration->passes;

        return [
            'order' => $order->fresh(['items', 'payments']),
            'registration' => $registration,
            'passes' => $passes,
        ];
    }

    /**
     * @param  array<string, mixed>  $orderMetadata
     */
    private function resolveReference(?string $reference, array $orderMetadata, Order $order): string
    {
        $reference = trim((string) $reference);

        if ($reference !== '') {
            return mb_substr($reference, 0, 120);
        }

        $storedReference = data_get($orderMetadata, 'offline_admission.payment_reference');

        if (is_string($storedReference) && trim($storedReference) !== '') {
            return trim($storedReference);
        }

        return 'offline-'.str()->uuid().'@'.$order->getKey();
    }
}
