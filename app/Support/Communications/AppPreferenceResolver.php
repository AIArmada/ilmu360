<?php

namespace App\Support\Communications;

use AIArmada\Communications\Contracts\PreferenceResolver;
use AIArmada\Communications\Enums\NotificationFamily;
use App\Models\NotificationSetting;
use App\Notifications\Channels\PushChannel;
use Illuminate\Database\Eloquent\Relations\Relation;

class AppPreferenceResolver implements PreferenceResolver
{
    public function isEnabled(
        ?string $recipientType,
        ?string $recipientId,
        string $channel,
        string $category,
    ): bool {
        $user = $this->resolveUser($recipientType, $recipientId);

        if ($user === null) {
            return true;
        }

        $setting = $user->notificationSetting;

        if (! $setting instanceof NotificationSetting) {
            return true;
        }

        $preferred = is_array($setting->preferred_channels) ? $setting->preferred_channels : [];

        if ($preferred !== [] && ! in_array($this->mapChannel($channel), $preferred, true)) {
            return false;
        }

        $appFamily = $this->familyToAppValue($category);

        if ($appFamily === null) {
            return true;
        }

        return $user->notificationRules()
            ->where('scope_key', $appFamily)
            ->where('enabled', true)
            ->exists();
    }

    public function isOptedIn(
        ?string $recipientType,
        ?string $recipientId,
        string $channel,
        string $category,
    ): ?bool {
        $user = $this->resolveUser($recipientType, $recipientId);

        if ($user === null) {
            return null;
        }

        $setting = $user->notificationSetting;

        if (! $setting instanceof NotificationSetting) {
            return null;
        }

        $preferred = is_array($setting->preferred_channels) ? $setting->preferred_channels : [];

        return in_array($this->mapChannel($channel), $preferred, true);
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
        return match ($channel) {
            'mail' => 'email',
            'database', InboxChannel::class => 'in_app',
            PushChannel::class => 'push',
            default => $channel,
        };
    }
}
