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
            ->select(['slug', 'updated_at'])
            ->limit(50000)
            ->cursor();

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
