<?php

namespace App\Features\Communications\Support\Integration;

use AIArmada\Communications\Contracts\PreferenceResolver;
use AIArmada\Communications\Enums\NotificationFamily;
use AIArmada\Communications\Models\CommunicationPreference;
use App\Features\Communications\Support\Normalization\EnumMapper;
use App\Features\Communications\Api\NotificationChannel;
use Illuminate\Database\Eloquent\Relations\Relation;

class AppPreferenceResolver implements PreferenceResolver
{
    public function isEnabled(
        ?string $recipientType,
        ?string $recipientId,
        string $channel,
        string $category,
    ): bool {
        $recipient = $this->resolveUser($recipientType, $recipientId);

        if ($recipient === null) {
            return true;
        }

        $setting = app(NotificationRecipientSettingsLookup::class)->basePreferenceForRecipient($recipient->getMorphClass(), $recipientId);

        if (! $setting instanceof CommunicationPreference) {
            return true;
        }

        $metadata = is_array($setting->metadata) ? $setting->metadata : [];
        $channels = is_array($metadata['channels'] ?? null) ? $metadata['channels'] : null;

        if ($channels !== null && ! in_array($this->mapChannel($channel), $channels, true)) {
            return false;
        }

        $appFamily = $this->familyToAppValue($category);

        if ($appFamily === null) {
            return true;
        }

        return CommunicationPreference::query()
            ->where('recipient_type', $recipientType)
            ->where('recipient_id', $recipientId)
            ->where('scope_key', $appFamily)
            ->whereNotNull('enabled_at')
            ->exists();
    }

    public function isOptedIn(
        ?string $recipientType,
        ?string $recipientId,
        string $channel,
        string $category,
    ): ?bool {
        $recipient = $this->resolveUser($recipientType, $recipientId);

        if ($recipient === null) {
            return null;
        }

        $setting = app(NotificationRecipientSettingsLookup::class)->basePreferenceForRecipient($recipient->getMorphClass(), $recipientId);

        if (! $setting instanceof CommunicationPreference) {
            return null;
        }

        $metadata = is_array($setting->metadata) ? $setting->metadata : [];
        $channels = is_array($metadata['channels'] ?? null) ? $metadata['channels'] : null;

        if ($channels === null) {
            return null;
        }

        return in_array($this->mapChannel($channel), $channels, true);
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

    private function familyToAppValue(string $category): ?string
    {
        $pkgFamily = NotificationFamily::tryFrom($category);

        if ($pkgFamily === null) {
            return null;
        }

        $appFamily = EnumMapper::toAppFamily($pkgFamily);

        return $appFamily?->value;
    }

    private function mapChannel(string $channel): string
    {
        return NotificationChannel::fromPackagePreferenceChannel($channel)?->value ?? $channel;
    }
}
