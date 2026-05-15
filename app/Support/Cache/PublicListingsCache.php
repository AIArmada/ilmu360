<?php

namespace App\Support\Cache;

use Illuminate\Support\Facades\Cache;

class PublicListingsCache
{
    public function bustHomepageStats(): void
    {
        Cache::forget('home.stats.events.upcoming');
        Cache::forget('home.stats.speakers.upcoming');
        Cache::forget('home.stats.institutions.upcoming');
    }

    public function bustMajlisListing(): void
    {
        Cache::forget('default_events_search_v2');
        Cache::forget('countries_all_v1');
        Cache::forget('states_all_v1');

        foreach ($this->supportedLocales() as $locale) {
            Cache::forget("events_institutions_{$locale}_v2");
            Cache::forget("events_speakers_{$locale}_v2");
            Cache::forget("events_disciplines_{$locale}_v2");
            Cache::forget("events_domains_{$locale}_v2");
            Cache::forget("events_sources_{$locale}_v2");
            Cache::forget("events_issues_{$locale}_v2");
            Cache::forget("events_references_{$locale}_v2");
            Cache::forget("events_venues_{$locale}_v2");
        }
    }

    /**
     * @return list<string>
     */
    private function supportedLocales(): array
    {
        $supportedLocales = config('app.supported_locales', []);

        if (is_array($supportedLocales) && $supportedLocales !== []) {
            $localeCodes = collect(array_keys($supportedLocales))
                ->filter(static fn (mixed $locale): bool => is_string($locale) && $locale !== '')
                ->map(static fn (mixed $locale): string => (string) $locale)
                ->values()
                ->all();

            if ($localeCodes !== []) {
                return $localeCodes;
            }
        }

        $defaultLocale = config('app.locale', 'ms');

        if (is_string($defaultLocale) && $defaultLocale !== '') {
            return [$defaultLocale];
        }

        return ['ms'];
    }
}
