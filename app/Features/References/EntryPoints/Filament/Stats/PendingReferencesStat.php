<?php

declare(strict_types=1);

namespace App\Features\References\EntryPoints\Filament\Stats;

use App\Features\References\EntryPoints\Filament\Resources\References\ReferenceResource;
use App\Features\References\Models\Reference;
use App\Platform\Admin\Filament\Stats\AdminStat;
use App\Platform\Admin\Filament\Stats\AdminStatProvider;

final readonly class PendingReferencesStat implements AdminStatProvider
{
    public function stats(): iterable
    {
        $count = Reference::query()->where('status', 'pending')->count();

        yield new AdminStat(
            label: 'References Needing Approval',
            count: $count,
            description: 'Review pending references',
            url: ReferenceResource::getUrl('index', panel: 'admin').'?tableFilters[status][value]=pending',
            sort: 40,
        );
    }
}
