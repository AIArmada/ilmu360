<?php

namespace App\Support\Communications;

use AIArmada\Communications\Contracts\RecipientSnapshotResolver;
use AIArmada\Communications\Data\RecipientSnapshotData;
use App\Models\User;

class AppRecipientSnapshotResolver implements RecipientSnapshotResolver
{
    public function resolve(mixed $notifiable): RecipientSnapshotData
    {
        $identifier = method_exists($notifiable, 'getKey')
            ? (string) $notifiable->getKey()
            : (string) spl_object_id($notifiable);

        if ($notifiable instanceof User) {
            $locale = $notifiable->preferredLocale();
            $timezone = $notifiable->preferredTimezone();

            return new RecipientSnapshotData(
                identifier: $identifier,
                displayName: $notifiable->name,
                email: $notifiable->email,
                phone: $notifiable->phone,
                locale: $locale,
                timezone: $timezone,
            );
        }

        $locale = null;
        $timezone = null;

        if (method_exists($notifiable, 'preferredLocale')) {
            $locale = $notifiable->preferredLocale();
        } elseif (property_exists($notifiable, 'locale') && is_string($notifiable->locale)) {
            $locale = $notifiable->locale;
        }

        if (method_exists($notifiable, 'preferredTimezone')) {
            $timezone = $notifiable->preferredTimezone();
        } elseif (property_exists($notifiable, 'timezone') && is_string($notifiable->timezone)) {
            $timezone = $notifiable->timezone;
        }

        return new RecipientSnapshotData(
            identifier: $identifier,
            displayName: method_exists($notifiable, 'getName') ? $notifiable->getName() : null,
            email: method_exists($notifiable, 'getEmail') ? $notifiable->getEmail() : null,
            phone: method_exists($notifiable, 'getPhone') ? $notifiable->getPhone() : null,
            locale: $locale,
            timezone: $timezone,
        );
    }
}
