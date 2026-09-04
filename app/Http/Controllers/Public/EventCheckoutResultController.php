<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use AIArmada\Checkout\Models\CheckoutSession;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Orders\Actions\GenerateReceipt;
use AIArmada\Orders\Models\Order;
use App\Http\Controllers\Controller;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class EventCheckoutResultController extends Controller
{
    public function show(Request $request, string $session): View
    {
        return OwnerContext::withOwner(null, function () use ($request, $session): View {
            $checkoutSession = $this->resolveSession($request, $session);
            $order = $this->resolveOrder($checkoutSession);

            return view('checkout.result', [
                'session' => $checkoutSession,
                'order' => $order,
                'registrations' => $order === null
                    ? new EloquentCollection
                    : $this->registrationsForOrder($order),
            ]);
        });
    }

    public function receipt(Request $request, string $session): mixed
    {
        return OwnerContext::withOwner(null, function () use ($request, $session): mixed {
            $checkoutSession = $this->resolveSession($request, $session);
            $order = $this->resolveOrder($checkoutSession);

            abort_unless($order instanceof Order && $order->isPaid(), 404);

            return app(GenerateReceipt::class)->download($order->load(['items', 'payments']));
        });
    }

    public function free(Request $request, string $registration): View
    {
        return OwnerContext::withOwner(null, function () use ($request, $registration): View {
            $user = $request->user();

            abort_unless($user instanceof User, 404);

            $selectedRegistration = Registration::query()
                ->with(['event', 'occurrence', 'session', 'participants.contactMethods', 'passes'])
                ->findOrFail($registration);

            abort_unless($selectedRegistration->isForUser($user), 404);

            $metadata = is_array($selectedRegistration->metadata) ? $selectedRegistration->metadata : [];
            $batchId = data_get($metadata, 'event_checkout.batch_id');

            abort_unless(is_string($batchId) && $batchId !== '', 404);

            $registrations = Registration::query()
                ->with(['event', 'occurrence', 'session', 'participants.contactMethods', 'passes'])
                ->where('event_id', $selectedRegistration->event_id)
                ->where('registrant_type', $user->getMorphClass())
                ->where('registrant_id', (string) $user->getKey())
                ->get()
                ->filter(fn (Registration $registration): bool => data_get(
                    $registration->metadata ?? [],
                    'event_checkout.batch_id',
                ) === $batchId)
                ->values();

            abort_unless($registrations->isNotEmpty(), 404);

            return view('checkout.free', [
                'registration' => $selectedRegistration,
                'registrations' => $registrations,
            ]);
        });
    }

    private function resolveSession(Request $request, string $sessionId): CheckoutSession
    {
        $checkoutSession = CheckoutSession::query()->with('order.items.purchasable')->findOrFail($sessionId);
        $user = $request->user();

        abort_unless($user instanceof User, 404);

        $actor = data_get($checkoutSession->payment_data ?? [], 'checkout_actor');
        $authorized = is_array($actor)
            && ($actor['type'] ?? null) === $user->getMorphClass()
            && (string) ($actor['id'] ?? '') === (string) $user->getKey();

        abort_unless($authorized, 404);

        return $checkoutSession;
    }

    private function resolveOrder(CheckoutSession $session): ?Order
    {
        $order = $session->relationLoaded('order') ? $session->getRelation('order') : $session->order;

        return $order instanceof Order ? $order : null;
    }

    /**
     * @return EloquentCollection<int, Registration>
     */
    private function registrationsForOrder(Order $order): EloquentCollection
    {
        return Registration::query()
            ->with(['event', 'occurrence', 'session', 'participants.contactMethods', 'passes'])
            ->where('external_order_id', (string) $order->getKey())
            ->where('external_order_type', $order::class)
            ->get();
    }
}
