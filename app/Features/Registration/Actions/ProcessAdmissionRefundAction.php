<?php

declare(strict_types=1);

namespace App\Features\Registration\Actions;

use AIArmada\Events\Data\EventRefundResultData;
use AIArmada\Events\Features\Commerce\Actions\ProcessEventRegistrationRefundAction;
use AIArmada\Orders\Enums\RefundStatus;
use AIArmada\Orders\Models\OrderRefund;
use App\Features\Registration\Api\AdmissionRefunds;
use App\Platform\Models\MorphModelResolver;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Owns the app Registration morph-resolution seam and delegates paid admission
 * refund orchestration to the canonical events package action. The provider
 * result is reported through the authoritative order refund record state.
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
        $userClass = MorphModelResolver::resolve('user');

        /** @var Model $actor */
        $actor = $userClass::query()->findOrFail($actorId);

        $result = $this->handle($eventId, $registrationId, $actor, $organizerOverride, $reason);

        $refundId = (string) $result->refundId;
        $refund = $refundId !== '' ? OrderRefund::query()->find($refundId) : null;
        $status = $refund instanceof OrderRefund ? $refund->status->value : $result->status;

        return [
            'refund_id' => $refundId,
            'status' => $status,
            'is_pending' => $status === RefundStatus::Pending->value,
        ];
    }

    public function handle(
        string $eventId,
        string $registrationId,
        Model $actor,
        bool $organizerOverride = false,
        ?string $reason = null,
    ): EventRefundResultData {
        return $this->refunds->handle(
            eventId: $eventId,
            registrationId: $registrationId,
            actor: $actor,
            organizerOverride: $organizerOverride,
            reason: $reason,
        );
    }
}
