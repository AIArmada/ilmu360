<?php

namespace App\Features\Communications\Support\Integration;

use AIArmada\Communications\Contracts\RecipientSnapshotResolver;
use AIArmada\Communications\Data\RecipientSnapshotData;
use App\Shared\Auth\UserMorphType;
use Illuminate\Database\Eloquent\Model;

final readonly class AppRecipientSnapshotResolver implements RecipientSnapshotResolver
{
    public function __construct(
        private UserMorphType $userMorphType,
    ) {}

    public function resolve(mixed $notifiable): RecipientSnapshotData
    {
        $identifier = method_exists($notifiable, 'getKey')
            ? (string) $notifiable->getKey()
            : (string) spl_object_id($notifiable);

        if ($notifiable instanceof Model && $notifiable->getMorphClass() === $this->userMorphType->morphClass()) {
            $locale = method_exists($notifiable, 'preferredLocale')
                ? $notifiable->preferredLocale()
                : $this->stringValue($notifiable->getAttribute('locale'));
            $timezone = method_exists($notifiable, 'preferredTimezone')
                ? $notifiable->preferredTimezone()
                : $this->stringValue($notifiable->getAttribute('timezone'));

            return new RecipientSnapshotData(
                identifier: $identifier,
                displayName: $this->stringValue($notifiable->getAttribute('name')),
                email: $this->stringValue($notifiable->getAttribute('email')),
                phone: $this->stringValue($notifiable->getAttribute('phone')),
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

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
