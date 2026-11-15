<?php

namespace App\Features\SlugRedirects\Queries;

use App\Features\SlugRedirects\Api\SlugRedirectLookup;
use App\Features\SlugRedirects\Api\SlugRedirectMatch;
use App\Features\SlugRedirects\Models\SlugRedirect;

final class DatabaseSlugRedirectLookup implements SlugRedirectLookup
{
    public function findBySourcePath(string $sourcePath): ?SlugRedirectMatch
    {
        $slugRedirect = SlugRedirect::query()
            ->where('source_path', $sourcePath)
            ->first(['id', 'redirectable_type', 'redirectable_id']);

        if (! $slugRedirect instanceof SlugRedirect) {
            return null;
        }

        return new SlugRedirectMatch(
            (string) $slugRedirect->getKey(),
            $slugRedirect->redirectable_type,
            $slugRedirect->redirectable_id,
        );
    }
}
