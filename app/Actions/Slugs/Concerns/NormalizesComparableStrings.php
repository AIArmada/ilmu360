<?php

declare(strict_types=1);

namespace App\Actions\Slugs\Concerns;

trait NormalizesComparableStrings
{
    protected function normalizeComparableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
