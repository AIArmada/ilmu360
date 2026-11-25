<?php

declare(strict_types=1);

namespace App\Features\Contributions\Support\Telemetry;

use App\Platform\Telemetry\Contracts\SignalSchemaContributor;

final class ContributionsSignalSchemaContributor implements SignalSchemaContributor
{
    public function supports(string $event): bool
    {
        return str_starts_with($event, 'contribution.');
    }

    /**
     * @return array<string, list<string>>
     */
    public function schemas(): array
    {
        return [
            'contribution.institution_maps_place_selected' => ['has_website', 'social_profile_count'],
            'contribution.hub_tab_changed' => ['tab'],
            'contribution.hub_launcher_clicked' => ['action', 'subject_type'],
            'contribution.hub_record_finder_opened' => ['mode', 'subject_type'],
            'contribution.hub_record_selected' => ['mode', 'subject_type'],
            'contribution.hub_detail_opened' => ['kind'],
            'contribution.request_cancelled' => ['request_type', 'subject_type'],
            'contribution.membership_claim_cancelled' => ['subject_type'],
        ];
    }
}
