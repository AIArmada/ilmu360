<?php

declare(strict_types=1);

namespace App\Features\Reports\EntryPoints\ApiDocumentation;

use App\Platform\ApiDocumentation\Contracts\RequestExampleContributor;

final class ReportsRequestExampleContributor implements RequestExampleContributor
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function examples(): array
    {
        return [
            'post reports' => [
                'entity_type' => 'event',
                'entity_id' => '019d1da4-cf8e-71f9-8c27-f60ef378d6f4',
                'category' => 'wrong_info',
                'description' => 'The start time on the poster does not match the event description.',
                'evidence' => ['poster-screenshot.jpg', 'venue-notice.pdf'],
            ],
        ];
    }
}
