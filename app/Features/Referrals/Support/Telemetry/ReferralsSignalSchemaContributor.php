<?php

declare(strict_types=1);

namespace App\Features\Referrals\Support\Telemetry;

use App\Platform\Telemetry\Contracts\SignalSchemaContributor;

final class ReferralsSignalSchemaContributor implements SignalSchemaContributor
{
    public function supports(string $event): bool
    {
        return str_starts_with($event, 'referrals.');
    }

    /**
     * @return array<string, list<string>>
     */
    public function schemas(): array
    {
        return [
            'referrals.invite_created' => ['program_id', 'invite_id', 'surface'],
            'referrals.invite_accepted' => ['program_id', 'invite_id', 'surface'],
        ];
    }
}
