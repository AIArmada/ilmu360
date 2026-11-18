<?php

declare(strict_types=1);

namespace App\Platform\Routing;

interface SitemapUrlProvider
{
    public function name(): string;

    /**
     * @return iterable<array{loc: string, lastmod: string, changefreq: string, priority: string}>
     */
    public function urls(): iterable;
}
