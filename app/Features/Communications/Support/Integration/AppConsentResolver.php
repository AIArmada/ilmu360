<?php

namespace App\Features\Communications\Support\Integration;

use AIArmada\Communications\Contracts\ConsentResolver;
use AIArmada\Communications\Data\ConsentDecisionData;
use App\Features\Communications\Api\NotificationChannel;
use Illuminate\Database\Eloquent\Relations\Relation;

class AppConsentResolver implements ConsentResolver
{
    public function resolveConsent(
        ?string $recipientType,
        ?string $recipientId,
        string $channel,
        string $category,
    ): ConsentDecisionData {
        $recipient = $this->resolveUser($recipientType, $recipientId);

        if ($recipient === null) {
            return new ConsentDecisionData(consented: true);
        }

        $appChannel = NotificationChannel::fromPackageConsentChannel($channel);

        if (! $appChannel instanceof NotificationChannel) {
            return new ConsentDecisionData(consented: true);
        }

        $hasActiveDestination = app(NotificationRecipientSettingsLookup::class)->hasActiveDestinationForRecipient(
            $recipient->getMorphClass(),
            $recipientId,
            $appChannel->value,
        );

        return new ConsentDecisionData(
            consented: $hasActiveDestination,
            reason: $hasActiveDestination ? null : "no_active_destination_for_{$appChannel->value}",
        );
    }

    private function resolveUser(?string $recipientType, ?string $recipientId): ?object
    {
        if ($recipientType === null || $recipientId === null) {
            return null;
        }

        $modelClass = Relation::getMorphedModel($recipientType)
            ?? (class_exists($recipientType) ? $recipientType : null);

        if ($modelClass === null) {
            return null;
        }

        return $modelClass::query()->find($recipientId);
    }

}
