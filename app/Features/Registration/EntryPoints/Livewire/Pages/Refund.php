<?php

declare(strict_types=1);

namespace App\Features\Registration\EntryPoints\Livewire\Pages;

use App\Features\Events\Models\Event;
use App\Features\Registration\Api\AdmissionRefunds;
use App\Features\Registration\Models\Registration;
use App\Features\Registration\Support\EventCommercePolicy;
use App\Models\User;
use App\Platform\Time\UserDateTimeFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

#[Layout('layouts.app')]
#[Title('Request a refund')]
final class Refund extends Component
{
    #[Locked]
    public Event $event;

    #[Locked]
    public Registration $registration;

    #[Locked]
    public string $eventId = '';

    #[Locked]
    public string $registrationId = '';

    public bool $confirmation = false;

    public bool $processing = false;

    #[Locked]
    public bool $submitted = false;

    #[Locked]
    public string $refundStatus = '';

    public function mount(Event $event, Registration $registration): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        if ((string) $registration->event_id !== (string) $event->getKey()) {
            abort(404);
        }

        abort_unless($user->can('requestRefund', $registration), 404);

        $this->event = $event;
        $this->registration = $registration->load(['occurrence', 'session', 'participants']);
        $this->eventId = (string) $event->getKey();
        $this->registrationId = (string) $registration->getKey();
    }

    public function submit(AdmissionRefunds $refunds): void
    {
        $user = $this->currentUserOrAbort();

        if ((string) $this->registration->event_id !== (string) $this->event->getKey()) {
            abort(404);
        }

        abort_unless($user->can('requestRefund', $this->registration), 404);

        $this->validate([
            'confirmation' => ['accepted'],
        ], [
            'confirmation.accepted' => __('Please confirm that you want to request this refund.'),
        ]);

        $this->processing = true;

        try {
            $result = $refunds->process(
                eventId: (string) $this->event->getKey(),
                registrationId: (string) $this->registration->getKey(),
                actorId: (string) $user->getKey(),
            );

            $this->refundStatus = $result['status'];
            $this->submitted = true;
            $this->registration->refresh();
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('refund', $exception->getMessage() !== ''
                ? $exception->getMessage()
                : __('We could not process the refund request. Please try again.'));
        } finally {
            $this->processing = false;
        }
    }

    public function refundAmount(): string
    {
        return number_format(((int) ($this->registration->total_amount ?? 0)) / 100, 2)
            .' '.mb_strtoupper((string) ($this->registration->currency ?: config('events.defaults.currency', 'MYR')));
    }

    public function refundDeadlineLabel(): string
    {
        $deadline = app(EventCommercePolicy::class)->selfServiceRefundDeadline($this->event, $this->registration);

        return $deadline instanceof CarbonImmutable
            ? UserDateTimeFormatter::translatedFormat($deadline, 'j F Y, h:i A')
            : __('the published refund deadline');
    }

    public function statusLabel(): string
    {
        return match ($this->refundStatus) {
            'completed' => __('Refund confirmed'),
            'pending' => __('Refund pending provider confirmation'),
            default => __('Refund request received'),
        };
    }

    public function render(): View
    {
        return view('livewire.pages.events.refund');
    }

    private function currentUserOrAbort(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
