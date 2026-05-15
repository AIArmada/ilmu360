<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('events')
            ->select([
                'id',
                'event_type',
                'age_group',
                'timing_mode',
                'prayer_reference',
                'prayer_offset',
                'prayer_display_text',
            ])
            ->orderBy('id')
            ->chunk(100, function ($events): void {
                foreach ($events as $event) {
                    $eventTypes = $this->normalizeEventTypes($event->event_type);
                    $ageGroups = $this->normalizeAgeGroups($event->age_group);
                    $timingMode = $this->normalizeTimingMode(
                        $event->timing_mode,
                        $event->prayer_reference,
                        $event->prayer_display_text,
                    );
                    $prayerReference = $this->normalizePrayerReference($event->prayer_reference);
                    $prayerOffset = $this->normalizePrayerOffset($event->prayer_offset);

                    $updates = [];

                    if ($this->jsonListChanged($event->event_type, $eventTypes)) {
                        $updates['event_type'] = json_encode($eventTypes, JSON_THROW_ON_ERROR);
                    }

                    if ($this->jsonListChanged($event->age_group, $ageGroups)) {
                        $updates['age_group'] = $ageGroups === []
                            ? null
                            : json_encode($ageGroups, JSON_THROW_ON_ERROR);
                    }

                    if ($this->stringValue($event->timing_mode) !== $timingMode) {
                        $updates['timing_mode'] = $timingMode;
                    }

                    if ($this->stringValue($event->prayer_reference) !== $prayerReference) {
                        $updates['prayer_reference'] = $prayerReference;
                    }

                    if ($this->stringValue($event->prayer_offset) !== $prayerOffset) {
                        $updates['prayer_offset'] = $prayerOffset;
                    }

                    if ($updates === []) {
                        continue;
                    }

                    DB::table('events')
                        ->where('id', $event->id)
                        ->update($updates);
                }
            });
    }

    /**
     * @return list<string>
     */
    private function normalizeEventTypes(mixed $rawValue): array
    {
        return $this->normalizeEnumList(
            $this->decodeList($rawValue),
            $this->eventTypeAliases(),
            'other',
        );
    }

    /**
     * @return list<string>
     */
    private function normalizeAgeGroups(mixed $rawValue): array
    {
        return $this->normalizeEnumList(
            $this->decodeList($rawValue),
            $this->ageGroupAliases(),
            'all_ages',
        );
    }

    /**
     * @param  list<string>  $values
     * @param  array<string, string>  $aliases
     * @return list<string>
     */
    private function normalizeEnumList(array $values, array $aliases, string $fallback): array
    {
        if ($values === []) {
            return [];
        }

        $normalized = [];

        foreach ($values as $value) {
            $key = $this->normalizeKey($value);

            if ($key === '') {
                continue;
            }

            $normalized[] = $aliases[$key] ?? $fallback;
        }

        return array_values(array_unique($normalized));
    }

    private function normalizeTimingMode(mixed $rawValue, mixed $prayerReference, mixed $prayerDisplayText): string
    {
        $key = $this->normalizeKey($this->stringValue($rawValue) ?? '');

        $aliases = [
            'absolute' => 'absolute',
            'custom' => 'absolute',
            'custom time' => 'absolute',
            'exact' => 'absolute',
            'exact time' => 'absolute',
            'fixed' => 'absolute',
            'fixed time' => 'absolute',
            'specific time' => 'absolute',
            'legacy prayer time' => 'prayer_relative',
            'prayer' => 'prayer_relative',
            'prayer relative' => 'prayer_relative',
            'prayer time' => 'prayer_relative',
            'relative prayer' => 'prayer_relative',
            'relative to prayer times' => 'prayer_relative',
        ];

        return $aliases[$key] ?? ($this->hasPrayerTimingData($prayerReference, $prayerDisplayText)
            ? 'prayer_relative'
            : 'absolute');
    }

    private function normalizePrayerReference(mixed $rawValue): ?string
    {
        return $this->normalizeNullableEnumValue(
            $rawValue,
            $this->prayerReferenceAliases(),
        );
    }

    private function normalizePrayerOffset(mixed $rawValue): ?string
    {
        return $this->normalizeNullableEnumValue(
            $rawValue,
            $this->prayerOffsetAliases(),
        );
    }

    /**
     * @param  array<string, string>  $aliases
     */
    private function normalizeNullableEnumValue(mixed $rawValue, array $aliases): ?string
    {
        $value = $this->stringValue($rawValue);

        if ($value === null) {
            return null;
        }

        $key = $this->normalizeKey($value);

        return $aliases[$key] ?? null;
    }

    /**
     * @return list<string>
     */
    private function decodeList(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_array($value)) {
            return $this->stringListFromArray($value);
        }

        if (! is_string($value)) {
            return is_scalar($value) ? [(string) $value] : [];
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return [];
        }

        try {
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [$trimmed];
        }

        if (is_array($decoded)) {
            return $this->stringListFromArray($decoded);
        }

        return is_scalar($decoded) ? [(string) $decoded] : [];
    }

    /**
     * @param  array<mixed>  $values
     * @return list<string>
     */
    private function stringListFromArray(array $values): array
    {
        $strings = [];

        foreach ($values as $value) {
            $string = $this->stringValue($value);

            if ($string === null) {
                continue;
            }

            $strings[] = $string;
        }

        return $strings;
    }

    /**
     * @param  list<string>  $normalized
     */
    private function jsonListChanged(mixed $rawValue, array $normalized): bool
    {
        if ($rawValue === null) {
            return $normalized !== [];
        }

        if (is_array($rawValue)) {
            return ! array_is_list($rawValue)
                || $this->stringListFromArray($rawValue) !== $normalized;
        }

        if (! is_string($rawValue)) {
            return $this->decodeList($rawValue) !== $normalized;
        }

        $trimmed = trim($rawValue);

        if ($trimmed === '') {
            return $normalized !== [];
        }

        try {
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return true;
        }

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            return true;
        }

        return $this->stringListFromArray($decoded) !== $normalized;
    }

    private function stringValue(mixed $value): ?string
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value !== '' ? $value : null;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return null;
    }

    private function hasPrayerTimingData(mixed $prayerReference, mixed $prayerDisplayText): bool
    {
        return $this->stringValue($prayerReference) !== null
            || $this->stringValue($prayerDisplayText) !== null;
    }

    private function normalizeKey(string $value): string
    {
        $key = strtolower(trim($value));
        $key = str_replace(["'", '`'], '', $key);
        $key = preg_replace('/[^a-z0-9]+/', ' ', $key) ?? $key;
        $key = preg_replace('/\s+/', ' ', $key) ?? $key;

        return trim($key);
    }

    /**
     * @return array<string, string>
     */
    private function eventTypeAliases(): array
    {
        return [
            'aqiqah' => 'aqiqah',
            'bacaan yasin' => 'bacaan_yasin',
            'berbuka puasa' => 'iftar',
            'ceramah' => 'kuliah_ceramah',
            'daurah' => 'kelas_daurah',
            'doa selamat' => 'doa_selamat',
            'forum' => 'forum',
            'forum perdana' => 'forum',
            'gotong royong' => 'gotong_royong',
            'hafazan al quran' => 'hafazan_quran',
            'hafazan quran' => 'hafazan_quran',
            'iftar' => 'iftar',
            'iftar berbuka puasa' => 'iftar',
            'kelas' => 'kelas_daurah',
            'kelas daurah' => 'kelas_daurah',
            'kenduri' => 'kenduri',
            'khatam al quran' => 'khatam_quran',
            'khatam quran' => 'khatam_quran',
            'khutbah jumaat' => 'khutbah_jumaat',
            'korban' => 'korban',
            'kuliah' => 'kuliah_ceramah',
            'kuliah ceramah' => 'kuliah_ceramah',
            'lain lain' => 'other',
            'majlis ilmu' => 'other',
            'other' => 'other',
            'qiamullail' => 'qiamullail',
            'sahur' => 'sahur',
            'seminar' => 'seminar_konvensyen',
            'seminar konvensyen' => 'seminar_konvensyen',
            'selawat' => 'selawat',
            'sesi tadabbur' => 'talim',
            'solat hajat' => 'solat_hajat',
            'tahlil' => 'tahlil',
            'talim' => 'talim',
            'tazkirah' => 'tazkirah',
            'tilawah' => 'tilawah',
            'tilawah al quran' => 'tilawah',
            'zikir' => 'zikir',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function ageGroupAliases(): array
    {
        return [
            'adults' => 'adults',
            'all' => 'all_ages',
            'all ages' => 'all_ages',
            'belia' => 'youth',
            'children' => 'children',
            'dewasa' => 'adults',
            'kanak kanak' => 'children',
            'remaja' => 'youth',
            'remaja belia' => 'youth',
            'semua' => 'all_ages',
            'semua peringkat' => 'all_ages',
            'semua peringkat umur' => 'all_ages',
            'seniors' => 'warga_emas',
            'warga emas' => 'warga_emas',
            'youth' => 'youth',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function prayerReferenceAliases(): array
    {
        return [
            'asar' => 'asr',
            'asr' => 'asr',
            'dhuhur' => 'dhuhr',
            'dhuhr' => 'dhuhr',
            'fajr' => 'fajr',
            'friday' => 'friday_prayer',
            'friday prayer' => 'friday_prayer',
            'isha' => 'isha',
            'isya' => 'isha',
            'isyak' => 'isha',
            'jumaat' => 'friday_prayer',
            'maghrib' => 'maghrib',
            'solat jumaat' => 'friday_prayer',
            'subuh' => 'fajr',
            'zohor' => 'dhuhr',
            'zuhur' => 'dhuhr',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function prayerOffsetAliases(): array
    {
        return [
            '15 min after' => 'after_15',
            '15 min before' => 'before_15',
            '15 minutes after' => 'after_15',
            '15 minutes before' => 'before_15',
            '15 minit selepas' => 'after_15',
            '15 minit sebelum' => 'before_15',
            '30 min after' => 'after_30',
            '30 min before' => 'before_30',
            '30 minutes after' => 'after_30',
            '30 minutes before' => 'before_30',
            '30 minit selepas' => 'after_30',
            '30 minit sebelum' => 'before_30',
            '45 min after' => 'after_45',
            '45 minutes after' => 'after_45',
            '45 minit selepas' => 'after_45',
            '60 min after' => 'after_60',
            '60 minutes after' => 'after_60',
            '1 jam selepas' => 'after_60',
            'after 15' => 'after_15',
            'after 30' => 'after_30',
            'after 45' => 'after_45',
            'after 60' => 'after_60',
            'before 15' => 'before_15',
            'before 30' => 'before_30',
            'immediate' => 'immediately',
            'immediately' => 'immediately',
            'right after' => 'immediately',
            'selepas' => 'immediately',
            'sejurus selepas' => 'immediately',
        ];
    }
};
