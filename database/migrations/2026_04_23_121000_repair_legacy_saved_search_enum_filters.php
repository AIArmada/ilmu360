<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('saved_searches')
            ->select(['id', 'filters'])
            ->whereNotNull('filters')
            ->orderBy('id')
            ->chunk(100, function ($savedSearches): void {
                foreach ($savedSearches as $savedSearch) {
                    $filters = $this->decodeFilters($savedSearch->filters);

                    if ($filters === null) {
                        continue;
                    }

                    $normalized = $this->normalizeFilters($filters);

                    if ($normalized === $filters) {
                        continue;
                    }

                    DB::table('saved_searches')
                        ->where('id', $savedSearch->id)
                        ->update([
                            'filters' => $normalized === []
                                ? null
                                : json_encode($normalized, JSON_THROW_ON_ERROR),
                        ]);
                }
            });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeFilters(mixed $value): ?array
    {
        if (is_array($value)) {
            /** @var array<string, mixed> $value */
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $filters): array
    {
        $this->normalizeListFilter($filters, 'event_type', $this->eventTypeAliases());
        $this->normalizeListFilter($filters, 'event_format', $this->eventFormatAliases());
        $this->normalizeListFilter($filters, 'age_group', $this->ageGroupAliases());
        $this->normalizeListFilter($filters, 'key_person_roles', $this->keyPersonRoleAliases(), preserveUnknown: false);
        $this->normalizeScalarFilter($filters, 'gender', $this->genderAliases());
        $this->normalizeScalarFilter($filters, 'prayer_time', $this->prayerTimeAliases());
        $this->normalizeScalarFilter($filters, 'timing_mode', $this->timingModeAliases());

        return $filters;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, string>  $aliases
     */
    private function normalizeListFilter(array &$filters, string $key, array $aliases, bool $preserveUnknown = true): void
    {
        if (! array_key_exists($key, $filters)) {
            return;
        }

        $values = [];

        foreach ($this->stringList($filters[$key]) as $value) {
            $normalized = $aliases[$this->normalizeKey($value)] ?? ($preserveUnknown ? $value : null);

            if ($normalized !== null) {
                $values[] = $normalized;
            }
        }

        if ($values === []) {
            unset($filters[$key]);

            return;
        }

        $filters[$key] = array_values(array_unique($values));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, string>  $aliases
     */
    private function normalizeScalarFilter(array &$filters, string $key, array $aliases): void
    {
        if (! array_key_exists($key, $filters)) {
            return;
        }

        $value = $this->stringValue($filters[$key]);

        if ($value === null) {
            unset($filters[$key]);

            return;
        }

        $filters[$key] = $aliases[$this->normalizeKey($value)] ?? $value;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];
        $strings = [];

        foreach ($values as $item) {
            $string = $this->stringValue($item);

            if ($string !== null) {
                $strings[] = $string;
            }
        }

        return $strings;
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string !== '' ? $string : null;
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
    private function eventFormatAliases(): array
    {
        return [
            'hybrid' => 'hybrid',
            'online' => 'online',
            'physical' => 'physical',
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
            'children' => 'children',
            'dewasa' => 'adults',
            'belia' => 'youth',
            'kanak kanak' => 'children',
            'remaja' => 'youth',
            'remaja belia' => 'youth',
            'semua' => 'all_ages',
            'semua peringkat' => 'all_ages',
            'semua peringkat umur' => 'all_ages',
            'warga emas' => 'warga_emas',
            'youth' => 'youth',
            'seniors' => 'warga_emas',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function keyPersonRoleAliases(): array
    {
        return [
            'bilal' => 'bilal',
            'imam' => 'imam',
            'khatib' => 'khatib',
            'moderator' => 'moderator',
            'person in charge' => 'person_in_charge',
            'pic penyelaras' => 'person_in_charge',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function genderAliases(): array
    {
        return [
            'all' => 'all',
            'lelaki sahaja' => 'men_only',
            'men only' => 'men_only',
            'semua lelaki wanita' => 'all',
            'wanita sahaja' => 'women_only',
            'women only' => 'women_only',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function prayerTimeAliases(): array
    {
        return [
            'lain waktu' => 'lain_waktu',
            'selepas asar' => 'selepas_asar',
            'selepas isyak' => 'selepas_isyak',
            'selepas jumaat' => 'selepas_jumaat',
            'selepas maghrib' => 'selepas_maghrib',
            'selepas subuh' => 'selepas_subuh',
            'selepas tarawih' => 'selepas_tarawih',
            'selepas zuhur' => 'selepas_zuhur',
            'sebelum jumaat' => 'sebelum_jumaat',
            'sebelum maghrib' => 'sebelum_maghrib',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function timingModeAliases(): array
    {
        return [
            'absolute' => 'absolute',
            'custom' => 'absolute',
            'custom time' => 'absolute',
            'exact' => 'absolute',
            'exact time' => 'absolute',
            'fixed' => 'absolute',
            'fixed time' => 'absolute',
            'prayer relative' => 'prayer_relative',
            'prayer time' => 'prayer_relative',
            'relative prayer' => 'prayer_relative',
        ];
    }
};
