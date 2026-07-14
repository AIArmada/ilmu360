<?php

namespace App\Support\Communications;

use AIArmada\Communications\Contracts\QuietHoursResolver;
use AIArmada\Communications\Models\CommunicationPreference;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;

class AppQuietHoursResolver implements QuietHoursResolver
{
    public function isInQuietHours(
        ?string $recipientType,
        ?string $recipientId,
    ): bool {
        if ($recipientType === null || $recipientId === null) {
            return false;
        }

        $setting = $this->resolveSetting($recipientType, $recipientId);

        if (!$setting instanceof \AIArmada\Communications\Models\CommunicationPreference) {
            return false;
        }

        $start = $setting->quiet_hours_start;
        $end = $setting->quiet_hours_end;

        if (! is_string($start) || ! is_string($end)) {
            return false;
        }

        $timezone = is_string($setting->timezone) ? $setting->timezone : 'UTC';
        $now = CarbonImmutable::now($timezone);
        $currentTime = $now->format('H:i:s');

        if ($start <= $end) {
            return $currentTime >= $start && $currentTime <= $end;
        }

        return $currentTime >= $start || $currentTime <= $end;
    }

    public function nextAllowedAt(
        ?string $recipientType,
        ?string $recipientId,
    ): ?string {
        if ($recipientType === null || $recipientId === null) {
            return null;
        }

        $setting = $this->resolveSetting($recipientType, $recipientId);

        if (!$setting instanceof \AIArmada\Communications\Models\CommunicationPreference) {
            return null;
        }

        $end = $setting->quiet_hours_end;

        if (! is_string($end)) {
            return null;
        }

        $timezone = is_string($setting->timezone) ? $setting->timezone : 'UTC';
        $now = CarbonImmutable::now($timezone);
        $todayEnd = $now->setTimeFromTimeString($end);

        if ($todayEnd->isPast()) {
            $todayEnd = $todayEnd->addDay();
        }

        return $todayEnd->toIso8601String();
    }

    private function resolveSetting(?string $recipientType, ?string $recipientId): ?CommunicationPreference
    {
        $modelClass = $recipientType !== null
            ? Relation::getMorphedModel($recipientType)
            : null;

        if ($modelClass === null && $recipientType !== null && class_exists($recipientType)) {
            $modelClass = $recipientType;
        }

        if ($modelClass === null || ! is_string($recipientId)) {
            return null;
        }

        if (! is_string($modelClass) || ! class_exists($modelClass)) {
            return null;
        }

        $user = $modelClass::query()->find($recipientId);

        if ($user === null || ! method_exists($user, 'notificationSetting')) {
            return null;
        }

        return $user->notificationSetting();
    }
}
