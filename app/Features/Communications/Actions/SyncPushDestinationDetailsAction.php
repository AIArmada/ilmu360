<?php

declare(strict_types=1);

namespace App\Features\Communications\Actions;

use AIArmada\Communications\Models\CommunicationDestination;
use App\Features\Communications\Api\PushDestinationDetails;
use Carbon\CarbonImmutable;
use Lorisleiva\Actions\Concerns\AsAction;

final class SyncPushDestinationDetailsAction
{
    use AsAction;

    public function handle(CommunicationDestination $destination, PushDestinationDetails $details): CommunicationDestination
    {
        $lastSeenAt = $details->lastSeenAt;

        $destination->forceFill([
            'external_id' => $details->fcmToken,
            'status' => 'active',
            'verified_at' => now(),
            'platform' => $details->platform,
            'app_version' => $details->appVersion,
            'device_label' => $details->deviceLabel,
            'locale' => $details->locale,
            'timezone' => $details->timezone,
            'last_seen_at' => is_string($lastSeenAt) && $lastSeenAt !== ''
                ? CarbonImmutable::parse($lastSeenAt)
                : now()->toImmutable(),
        ])->save();

        return $destination;
    }
}
