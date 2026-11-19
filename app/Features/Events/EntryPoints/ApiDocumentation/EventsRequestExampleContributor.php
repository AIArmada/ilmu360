<?php

declare(strict_types=1);

namespace App\Features\Events\EntryPoints\ApiDocumentation;

use App\Platform\ApiDocumentation\Contracts\RequestExampleContributor;

final class EventsRequestExampleContributor implements RequestExampleContributor
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function examples(): array
    {
        return [
            'post events/{event}/registrations' => [
                'name' => 'API Guest',
                'email' => 'guest@example.com',
                'phone' => '+60112223344',
            ],
        ];
    }
}
