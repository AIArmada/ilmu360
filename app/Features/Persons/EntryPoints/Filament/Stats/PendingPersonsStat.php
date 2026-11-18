<?php

declare(strict_types=1);

namespace App\Features\Persons\EntryPoints\Filament\Stats;

use App\Features\Persons\EntryPoints\Filament\Resources\Persons\PersonResource;
use App\Features\Persons\Models\Person;
use App\Platform\Admin\Filament\Stats\AdminStat;
use App\Platform\Admin\Filament\Stats\AdminStatProvider;

final readonly class PendingPersonsStat implements AdminStatProvider
{
    public function stats(): iterable
    {
        $count = Person::query()->where('status', 'pending')->count();

        yield new AdminStat(
            label: 'Persons Needing Approval',
            count: $count,
            description: 'Review pending persons',
            url: PersonResource::getUrl('index', panel: 'admin').'?tableFilters[status][value]=pending',
        );
    }
}
