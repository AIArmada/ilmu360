<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Dashboard\Events;

use AIArmada\Orders\Enums\PaymentStatus as OrderPaymentStatus;
use AIArmada\Orders\Models\Order;
use AIArmada\Orders\Models\OrderPayment;
use App\Actions\Events\ProcessEventRefundAction;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use App\Support\Events\EventCommercePolicy;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

#[Layout('layouts.app')]
#[Title('Event refunds')]
final class Refunds extends Component
{
    #[Locked]
    public string $eventId = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public string $selectedRegistrationId = '';

    public string $reason = '';

    public bool $processing = false;

    public ?string $successMessage = null;

    public function mount(Event $event): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);
        abort_unless($user->can('manageAdmissions', $event), 403);
        abort_unless(app(EventCommercePolicy::class)->refundsEnabled($event), 404);

        $this->eventId = (string) $event->getKey();
    }

    public function updatedSearch(): void
    {
        $this->successMessage = null;
    }

    public function selectRegistration(string $registrationId): void
    {
        $registration = $this->registrationQuery()
            ->whereKey($registrationId)
            ->first();

        if (! $registration instanceof Registration) {
            $this->addError('registration', __('This admission is no longer available for refund.'));

            return;
        }

        $this->selectedRegistrationId = (string) $registration->getKey();
        $this->reason = '';
        $this->successMessage = null;
        $this->resetErrorBag();
    }

    public function clearSelection(): void
    {
        $this->selectedRegistrationId = '';
        $this->reason = '';
        $this->successMessage = null;
        $this->resetErrorBag();
    }

    public function submit(ProcessEventRefundAction $processRefund): void
    {
        $this->validate([
            'selectedRegistrationId' => ['required', 'string'],
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'selectedRegistrationId.required' => __('Select an admission first.'),
            'reason.required' => __('An organizer refund requires a reason.'),
        ]);

        $event = $this->selectedEvent();
        $registration = $this->registrationQuery()->whereKey($this->selectedRegistrationId)->firstOrFail();
        $actor = $this->currentUserOrAbort();
        $this->processing = true;

        try {
            $refund = $processRefund->handle(
                event: $event,
                registration: $registration,
                actor: $actor,
                organizerOverride: true,
                reason: $this->reason,
            );

            $message = $refund->isPending()
                ? __('Refund started and is waiting for provider confirmation.')
                : __('Refund completed and the admission is no longer valid.');
            $this->clearSelection();
            $this->successMessage = $message;
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('refund', $exception->getMessage() !== ''
                ? $exception->getMessage()
                : __('We could not process this refund. Please try again.'));
        } finally {
            $this->processing = false;
        }
    }

    /** @return Collection<int, Registration> */
    #[Computed]
    public function registrationRows(): Collection
    {
        return $this->registrationQuery()
            ->with(['participants.contactMethods', 'occurrence', 'session'])
            ->orderByDesc('registered_at')
            ->limit(100)
            ->get();
    }

    public function selectedRegistration(): ?Registration
    {
        if ($this->selectedRegistrationId === '') {
            return null;
        }

        $registration = $this->registrationQuery()
            ->with(['participants.contactMethods', 'occurrence', 'session'])
            ->whereKey($this->selectedRegistrationId)
            ->first();

        return $registration instanceof Registration ? $registration : null;
    }

    public function formatMoney(Registration $registration): string
    {
        return number_format(((int) ($registration->total_amount ?? 0)) / 100, 2)
            .' '.mb_strtoupper((string) ($registration->currency ?: config('events.defaults.currency', 'MYR')));
    }

    public function render(): View
    {
        return view('livewire.pages.dashboard.events.refunds', [
            'event' => $this->selectedEvent(),
            'registrations' => $this->registrationRows(),
            'selectedRegistration' => $this->selectedRegistration(),
        ]);
    }

    /** @return Builder<Registration> */
    private function registrationQuery(): Builder
    {
        $query = Registration::query()
            ->where('event_id', $this->eventId)
            ->where('external_order_type', Order::class)
            ->whereIn('status', ['confirmed', 'completed', 'checked_in', 'no_show'])
            ->where('total_amount', '>', 0);

        // Offline admissions are reconciled outside the online payment
        // provider flow. Do not present them as refundable here: creating an
        // online refund record for cash or bank-transfer money would claim a
        // provider-side refund that never happened.
        $registrationTable = $query->getModel()->getTable();
        $paymentTable = (new OrderPayment)->getTable();

        $query->whereExists(function (Builder $paymentQuery) use ($paymentTable, $registrationTable): void {
            $paymentQuery
                ->selectRaw('1')
                ->from($paymentTable)
                ->whereColumn("{$paymentTable}.order_id", "{$registrationTable}.external_order_id")
                ->where("{$paymentTable}.status", OrderPaymentStatus::Completed)
                ->whereNotLike("{$paymentTable}.gateway", 'offline_%');
        });

        $term = trim($this->search);

        if ($term === '') {
            return $query;
        }

        $like = '%'.$term.'%';

        return $query->where(function (Builder $searchQuery) use ($like): void {
            $searchQuery
                ->whereLike('registration_no', $like)
                ->orWhereHas('participants', function (Builder $participantQuery) use ($like): void {
                    $participantQuery
                        ->whereLike('name', $like)
                        ->orWhereHas('contactMethods', fn (Builder $contactQuery): Builder => $contactQuery
                            ->whereLike('value', $like)
                            ->orWhereLike('normalized_value', $like));
                });
        });
    }

    private function selectedEvent(): Event
    {
        /** @var Event $event */
        $event = Event::query()->findOrFail($this->eventId);

        return $event;
    }

    private function currentUserOrAbort(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
