<?php

declare(strict_types=1);

namespace App\Models;

use AIArmada\CommerceSupport\Models\Language as CommerceLanguage;
use Illuminate\Support\Str;

class Language extends CommerceLanguage
{
    /**
     * Map commerce language IDs to codes in input order.
     *
     * Non-scalar values are rejected instead of string-cast; only UUID
     * strings reach the query, deduped in O(n).
     *
     * @param  list<mixed>  $ids
     * @return list<string>
     */
    public static function codesForIds(array $ids): array
    {
        $seen = [];
        $normalized = [];

        foreach ($ids as $id) {
            if (! is_scalar($id)) {
                continue;
            }

            $key = trim((string) $id);

            if ($key === '' || ! Str::isUuid($key) || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = $key;
        }

        if ($normalized === []) {
            return [];
        }

        $codesById = static::query()
            ->whereIn('languages.id', $normalized)
            ->pluck('languages.code', 'languages.id');

        $codes = [];

        foreach ($normalized as $id) {
            $code = $codesById->get($id);

            if (is_string($code) && $code !== '') {
                $codes[] = $code;
            }
        }

        return $codes;
    }
}
