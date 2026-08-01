<?php

declare(strict_types=1);

namespace App\Actions\Slugs\Concerns;

use Illuminate\Database\Eloquent\Model;

trait BuildsUniqueSlug
{
    private const int MAX_SLUG_LENGTH = 200;

    /**
     * @param  class-string<Model>  $modelClass
     * @param  list<string>  $middleSegments
     */
    protected function buildUniqueSlug(
        string $modelClass,
        string $baseSlug,
        array $middleSegments = [],
        string $trailingSuffix = '',
        ?string $ignoreId = null,
    ): string {
        $slugSet = array_flip($modelClass::query()
            ->where('slug', $baseSlug)
            ->orWhereLike('slug', $baseSlug.'-%')
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->pluck('slug')
            ->toArray());

        $sequence = 1;

        do {
            $candidateParts = [$baseSlug];

            foreach ($middleSegments as $segment) {
                if ($segment !== '') {
                    $candidateParts[] = $segment;
                }
            }

            if ($sequence > 1) {
                $candidateParts[] = (string) $sequence;
            }

            if ($trailingSuffix !== '') {
                $candidateParts[] = $trailingSuffix;
            }

            $candidate = mb_substr(implode('-', $candidateParts), 0, self::MAX_SLUG_LENGTH);
            $sequence++;
        } while (isset($slugSet[$candidate]));

        return $candidate;
    }
}
