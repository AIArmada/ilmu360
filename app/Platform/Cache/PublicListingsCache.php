<?php

declare(strict_types=1);

namespace App\Platform\Cache;

use Illuminate\Support\Facades\Cache;

final class PublicListingsCache
{
    public function bustHomepageStats(): void
    {
        Cache::forget('home.stats.events.active');
        Cache::forget('home.stats.persons.active');
        Cache::forget('home.stats.institutions.active');
        Cache::forget('home.stats.references.active');
    }

    public function bustMajlisListing(): void
    {
        Cache::forget('default_events_search_v2');
    }
}
