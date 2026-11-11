<?php

declare(strict_types=1);

namespace App\Features\Registration\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Models\EventRegistration;
use App\Features\Registration\Api\AdmissionCheckout;
use App\Platform\Models\MorphModelResolver;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Owns the free-checkout registration metadata write path.
 *
 * The root checkout transport creates the batch id and performs the redirect;
 * this action records the `event_checkout` batch and agreement evidence on
 * every registration returned by the free-admission writer. The merge is
 * recursive so per-participant checkout metadata (such as a protected identity
 * document) and unrelated registration metadata survive unchanged.
 */
final class RecordFreeCheckoutMetadataAction implements AdmissionCheckout
{
    use AsAction;

    /**
     * @param  list<string>  $registrationIds
     * @param  array<string, mixed>  $agreement
     */
    public function recordCheckoutMetadata(array $registrationIds, string $batchId, array $agreement): void
    {
        $registrationClass = MorphModelResolver::resolve('registration');

        OwnerContext::withOwner(null, static function () use ($agreement, $batchId, $registrationClass, $registrationIds): void {
            foreach ($registrationIds as $registrationId) {
                /** @var EventRegistration $registration */
                $registration = $registrationClass::query()->findOrFail($registrationId);
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
        });
    }
}
