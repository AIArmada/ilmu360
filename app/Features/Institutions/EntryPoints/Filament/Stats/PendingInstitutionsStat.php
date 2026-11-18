<?php

declare(strict_types=1);

namespace App\Features\Institutions\EntryPoints\Filament\Stats;

use App\Features\Institutions\EntryPoints\Filament\Resources\Institutions\InstitutionResource;
use App\Features\Institutions\Models\Institution;
use App\Platform\Admin\Filament\Stats\AdminStat;
use App\Platform\Admin\Filament\Stats\AdminStatProvider;

final readonly class PendingInstitutionsStat implements AdminStatProvider
{
    public function stats(): iterable
    {
        $count = Institution::query()->where('status', 'pending')->count();

        yield new AdminStat(
            label: 'Institutions Needing Approval',
            count: $count,
            sort: 30,
            description: 'Review pending institutions',
            url: InstitutionResource::getUrl('index', panel: 'admin').'?tableFilters[status][value]=pending',
        );
    }
}
