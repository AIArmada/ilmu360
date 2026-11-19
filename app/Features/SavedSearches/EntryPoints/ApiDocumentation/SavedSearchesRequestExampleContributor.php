<?php

declare(strict_types=1);

namespace App\Features\SavedSearches\EntryPoints\ApiDocumentation;

use App\Platform\ApiDocumentation\Contracts\RequestExampleContributor;

final class SavedSearchesRequestExampleContributor implements RequestExampleContributor
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function examples(): array
    {
        return [
            'post saved-searches' => [
                'name' => 'Kuliah Maghrib Kuala Lumpur',
                'query' => 'muamalat',
                'filters' => [
                    'language_codes' => ['ms'],
                    'event_category_ids' => ['00000000-0000-0000-0000-000000000000'],
                    'event_format' => ['physical'],
                    'gender' => 'all',
                    'age_group' => ['all_ages'],
                    'prayer_time' => 'selepas_maghrib',
                    'timing_mode' => 'prayer_relative',
                ],
                'radius_km' => 25,
                'lat' => 3.139,
                'lng' => 101.6869,
                'notify' => 'daily',
            ],
            'put saved-searches/{savedSearch}' => [
                'name' => 'Kuliah Maghrib KL',
                'filters' => [
                    'language_codes' => ['ms', 'en'],
                    'event_category_ids' => ['00000000-0000-0000-0000-000000000000'],
                    'event_format' => ['online'],
                    'gender' => 'all',
                    'age_group' => ['youth'],
                ],
                'notify' => 'instant',
            ],
        ];
    }
}
