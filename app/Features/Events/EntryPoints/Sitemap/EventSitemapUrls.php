<?php

declare(strict_types=1);

namespace App\Features\Events\EntryPoints\Sitemap;

use App\Features\Events\Models\Event;
use App\Platform\Routing\SitemapUrlProvider;

final readonly class EventSitemapUrls implements SitemapUrlProvider
{
    public function name(): string
    {
        return 'events';
    }

    public function urls(): iterable
    {
        $events = Event::query()
            ->active()
            ->orderBy('updated_at', 'desc')
            ->take(50000)
            ->get(['slug', 'updated_at']);

        foreach ($events as $event) {
            yield [
                'loc' => route('events.show', $event->slug),
                'lastmod' => $event->updated_at->toW3cString(),
                'changefreq' => 'weekly',
                'priority' => '0.8',
            ];
        }
    }
}
