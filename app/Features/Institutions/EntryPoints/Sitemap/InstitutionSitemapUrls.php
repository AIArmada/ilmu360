<?php

declare(strict_types=1);

namespace App\Features\Institutions\EntryPoints\Sitemap;

use App\Features\Institutions\Models\Institution;
use App\Platform\Routing\SitemapUrlProvider;

final readonly class InstitutionSitemapUrls implements SitemapUrlProvider
{
    public function name(): string
    {
        return 'institutions';
    }

    public function urls(): iterable
    {
        $institutions = Institution::query()
            ->active()
            ->orderBy('updated_at', 'desc')
            ->select(['slug', 'updated_at'])
            ->limit(50000)
            ->cursor();

        foreach ($institutions as $institution) {
            yield [
                'loc' => route('institutions.show', $institution->slug),
                'lastmod' => $institution->updated_at->toW3cString(),
                'changefreq' => 'monthly',
                'priority' => '0.6',
            ];
        }
    }
}
