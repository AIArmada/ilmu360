<?php

declare(strict_types=1);

namespace App\Actions\Slugs\Concerns;

use AIArmada\CommerceSupport\Support\SlugGenerator;

trait BuildsUniqueSlug
{
    /**
     * @param  list<string>  $middleSegments
     */
    protected function buildUniqueSlug(
        string $modelClass,
        string $baseSlug,
        array $middleSegments = [],
        string $trailingSuffix = '',
        ?string $ignoreId = null,
    ): string {
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

            $candidate = implode('-', $candidateParts);
            $sequence++;
        } while (SlugGenerator::exists($modelClass, $candidate, $ignoreId));

        return $candidate;
    }
}
