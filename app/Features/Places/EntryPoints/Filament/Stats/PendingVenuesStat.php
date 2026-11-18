<?php

declare(strict_types=1);

namespace App\Features\Places\EntryPoints\Filament\Stats;

use App\Features\Places\EntryPoints\Filament\Resources\Venues\VenueResource;
use App\Features\Places\Models\Venue;
use App\Platform\Admin\Filament\Stats\AdminStat;
use App\Platform\Admin\Filament\Stats\AdminStatProvider;

final readonly class PendingVenuesStat implements AdminStatProvider
{
    public function stats(): iterable
    {
        $count = Venue::query()->where('status', 'pending')->count();

        yield new AdminStat(
            label: 'Venues Needing Approval',
            count: $count,
            description: 'Review pending venues',
            url: VenueResource::getUrl('index', panel: 'admin').'?tableFilters[status][value]=pending',
        );
    }
}
