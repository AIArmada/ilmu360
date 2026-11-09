<?php

declare(strict_types=1);

namespace App\Features\Identity\Actions;

use App\Features\Identity\Api\PrayerType;
use Illuminate\Support\Facades\DB;

/**
 * Replace one prayer preference set for an account.
 *
 * Rows are linked by institution UUID only; no institution model is resolved
 * here, so an account restoration relinks the same pivot rows by key.
 */
final class SyncPrayerInstitutionsAction
{
    /**
     * @param  list<string>  $institutionIds
     */
    public function handle(string $accountId, PrayerType $prayerType, array $institutionIds): void
    {
        DB::transaction(function () use ($accountId, $prayerType, $institutionIds): void {
            $table = DB::table('user_prayer_institutions');

            $currentIds = $table
                ->where('user_id', $accountId)
                ->where('prayer_type', $prayerType->value)
                ->pluck('institution_id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all();

            $detachedIds = array_values(array_diff($currentIds, $institutionIds));

            if ($detachedIds !== []) {
                $table
                    ->where('user_id', $accountId)
                    ->where('prayer_type', $prayerType->value)
                    ->whereIn('institution_id', $detachedIds)
                    ->delete();
            }

            $attachedIds = array_values(array_diff($institutionIds, $currentIds));

            if ($attachedIds === []) {
                return;
            }

            $now = now();

            $table->insertOrIgnore(array_map(
                static fn (string $institutionId): array => [
                    'user_id' => $accountId,
                    'institution_id' => $institutionId,
                    'prayer_type' => $prayerType->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                $attachedIds,
            ));
        });
    }
}
