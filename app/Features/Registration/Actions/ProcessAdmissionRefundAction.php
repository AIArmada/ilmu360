<?php

declare(strict_types=1);

namespace App\Features\Registration\Actions;

use AIArmada\Events\Actions\ProcessEventRegistrationRefundAction;
use AIArmada\Events\Models\Event as PackageEvent;
use AIArmada\Events\Models\EventRegistration;
use AIArmada\Orders\Models\OrderRefund;
use App\Features\Registration\Api\AdmissionRefunds;
use App\Platform\Models\MorphModelResolver;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Owns the app Registration morph-resolution seam and delegates paid admission
 * refund orchestration to the canonical events package action.
 */
final readonly class ProcessAdmissionRefundAction implements AdmissionRefunds
{
    use AsAction;

    public function __construct(
        private ProcessEventRegistrationRefundAction $refunds,
    ) {}

    /**
     * @return array{refund_id: string, status: string, is_pending: bool}
     */
    public function process(
        string $eventId,
        string $registrationId,
        string $actorId,
        bool $organizerOverride = false,
        ?string $reason = null,
    ): array {
        $eventClass = MorphModelResolver::resolve('event');
        $registrationClass = MorphModelResolver::resolve('registration');
        $userClass = MorphModelResolver::resolve('user');

        /** @var PackageEvent $event */
        $event = $eventClass::query()->findOrFail($eventId);

        /** @var EventRegistration $registration */
        $registration = $registrationClass::query()->findOrFail($registrationId);

        /** @var Model $actor */
        $actor = $userClass::query()->findOrFail($actorId);

        $refund = $this->handle($event, $registration, $actor, $organizerOverride, $reason);

        return [
            'refund_id' => (string) $refund->getKey(),
            'status' => $refund->status->value,
            'is_pending' => $refund->isPending(),
        ];
    }

    public function handle(
        PackageEvent $event,
        EventRegistration $registration,
        Model $actor,
        bool $organizerOverride = false,
        ?string $reason = null,
    ): OrderRefund {
        return $this->refunds->handle(
            event: $event,
            registration: $registration,
            actor: $actor,
            organizerOverride: $organizerOverride,
            reason: $reason,
        );
    }
}
