<?php

declare(strict_types=1);

namespace App\Support\Communications;

use AIArmada\Communications\Contracts\DestinationProtector;
use AIArmada\Communications\Contracts\DestinationResolver;
use AIArmada\Communications\Data\ResolvedDestinationData;
use AIArmada\Communications\Services\CommunicationDestinationResolver;
use AIArmada\Events\Models\EventRegistration as PackageEventRegistration;
use Illuminate\Notifications\Notification;
use TypeError;

/**
 * Destination resolver that tolerates notification-requiring mail routes.
 *
 * Destination resolution probes notifiables with routeNotificationFor($channel)
 * and no notification instance. Package registration mail routes require a
 * notification argument even though the lookup only needs the primary
 * participant, so the probe call throws a TypeError. Retry those probes with
 * a blank notification instead of crashing the whole dispatch.
 */
final class ProbeSafeDestinationResolver implements DestinationResolver
{
    public function __construct(
        private readonly CommunicationDestinationResolver $inner,
        private readonly DestinationProtector $protector,
    ) {}

    public function resolve(mixed $notifiable, string $channel): ?ResolvedDestinationData
    {
        try {
            return $this->inner->resolve($notifiable, $channel);
        } catch (TypeError $exception) {
            if (! $notifiable instanceof PackageEventRegistration || $channel !== 'mail') {
                throw $exception;
            }

            $route = $notifiable->routeNotificationForMail(new class extends Notification {});

            $address = is_array($route) ? array_key_first($route) : $route;

            if (! is_string($address) || $address === '') {
                return null;
            }

            return new ResolvedDestinationData(
                destination: $address,
                channel: $channel,
                ciphertext: $this->protector->encrypt($address),
                hash: $this->protector->hash($address),
                hint: $this->protector->hint($address),
            );
        }
    }
}
