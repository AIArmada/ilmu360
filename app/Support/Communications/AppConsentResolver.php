<?php

namespace App\Support\Communications;

use AIArmada\Communications\Contracts\ConsentResolver;
use AIArmada\Communications\Data\ConsentDecisionData;
use App\Enums\NotificationChannel;
use App\Enums\NotificationChannel as AppChannel;
use Illuminate\Database\Eloquent\Relations\Relation;

class AppConsentResolver implements ConsentResolver
{
    public function resolve(
        ?string $recipientType,
        ?string $recipientId,
        string $channel,
        string $category,
    ): ConsentDecisionData {
        $user = $this->resolveUser($recipientType, $recipientId);

        if ($user === null) {
            return new ConsentDecisionData(consented: true);
        }

        $appChannel = $this->toAppChannel($channel);

        if (! $appChannel instanceof NotificationChannel) {
            return new ConsentDecisionData(consented: true);
        }

        $hasActiveDestination = $user->notificationDestinations()
            ->where('channel', $appChannel->value)
            ->where('status', 'active')
            ->exists();

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
            'mail' => AppChannel::Email,
            'database', 'in_app' => AppChannel::InApp,
            'push', 'fcm' => AppChannel::Push,
            'whatsapp' => AppChannel::Whatsapp,
            default => AppChannel::tryFrom($channel),
        };
    }
}
