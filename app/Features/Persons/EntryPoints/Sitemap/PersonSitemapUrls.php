<?php

declare(strict_types=1);

namespace App\Features\Persons\EntryPoints\Sitemap;

use App\Features\Persons\Models\Person;
use App\Platform\Routing\SitemapUrlProvider;

final readonly class PersonSitemapUrls implements SitemapUrlProvider
{
    public function name(): string
    {
        return 'persons';
    }

    public function urls(): iterable
    {
        $persons = Person::query()
            ->active()
            ->speakers()
            ->orderBy('updated_at', 'desc')
            ->select(['slug', 'updated_at'])
            ->limit(50000)
            ->cursor();

        foreach ($persons as $person) {
            yield [
                'loc' => route('persons.show', $person->slug),
                'lastmod' => $person->updated_at->toW3cString(),
                'changefreq' => 'monthly',
                'priority' => '0.5',
            ];
        }
    }
}
