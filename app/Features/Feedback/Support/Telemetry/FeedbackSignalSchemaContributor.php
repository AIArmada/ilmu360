<?php

declare(strict_types=1);

namespace App\Features\Feedback\Support\Telemetry;

use App\Platform\Telemetry\Contracts\SignalSchemaContributor;

final class FeedbackSignalSchemaContributor implements SignalSchemaContributor
{
    public function supports(string $event): bool
    {
        return str_starts_with($event, 'app_feedback.');
    }

    /**
     * @return array<string, list<string>>
     */
    public function schemas(): array
    {
        return [
            'app_feedback.submitted' => ['category', 'status'],
        ];
    }
}
