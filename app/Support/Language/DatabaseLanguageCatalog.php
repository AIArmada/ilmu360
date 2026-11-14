<?php

namespace App\Support\Language;

use App\Models\Language;
use App\Shared\Language\LanguageCatalog;

final class DatabaseLanguageCatalog implements LanguageCatalog
{
    public function codesIn(array $codes): array
    {
        return Language::query()
            ->whereIn('code', $codes)
            ->pluck('code')
            ->map(static fn (mixed $code): string => strtolower((string) $code))
            ->values()
            ->all();
    }

    public function idsForCodes(array $codes): array
    {
        return Language::query()
            ->whereIn('code', $codes)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }
}
