<?php

namespace App\Support\Communications;

use AIArmada\Communications\Contracts\ConsentResolver;
use AIArmada\Communications\Data\ConsentDecisionData;
use App\Features\Communications\Api\NotificationChannel;
use App\Features\Communications\Api\NotificationChannel as AppChannel;
use App\Notifications\Channels\DigestMailChannel;
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

        $appChannel = $this->toAppChannel($channel);

        if (! $appChannel instanceof NotificationChannel) {
            return new ConsentDecisionData(consented: true);
        }

        $hasActiveDestination = app(AccountCommunications::class)->hasActiveDestinationForRecipient(
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

    private function toAppChannel(string $channel): ?AppChannel
    {
        return match ($channel) {
            'mail', DigestMailChannel::class => AppChannel::Email,
            'database', 'in_app' => AppChannel::InApp,
            'push', 'fcm' => AppChannel::Push,
            default => AppChannel::tryFrom($channel),
        };
    }
}
