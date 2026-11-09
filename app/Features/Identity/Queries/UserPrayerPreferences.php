<?php

declare(strict_types=1);

namespace App\Features\Identity\Queries;

use App\Features\Identity\Api\PrayerType;
use Illuminate\Support\Facades\DB;

final class UserPrayerPreferences
{
    /**
     * Institution ids ordered the same way as the former user relations:
     * by institution name.
     *
     * @return list<string>
     */
    public function institutionIds(string $accountId, PrayerType $prayerType): array
    {
        return DB::table('user_prayer_institutions as preference')
            ->join('institutions', 'institutions.id', '=', 'preference.institution_id')
            ->where('preference.user_id', $accountId)
            ->where('preference.prayer_type', $prayerType->value)
            ->orderBy('institutions.name')
            ->pluck('preference.institution_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }
}
