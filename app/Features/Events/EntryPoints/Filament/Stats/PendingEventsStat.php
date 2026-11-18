<?php

declare(strict_types=1);

namespace App\Features\Events\EntryPoints\Filament\Stats;

use App\Features\Events\EntryPoints\Filament\Pages\ModerationQueue;
use App\Features\Events\Models\Event;
use App\Platform\Admin\Filament\Stats\AdminStat;
use App\Platform\Admin\Filament\Stats\AdminStatProvider;

final readonly class PendingEventsStat implements AdminStatProvider
{
    public function stats(): iterable
    {
        $count = Event::query()->where('status', 'pending')->count();

        yield new AdminStat(
            label: 'Events Needing Approval',
            count: $count,
            sort: 10,
            description: 'Open pending moderation queue',
            url: ModerationQueue::getUrl(panel: 'admin').'?tab=pending',
        );
    }
}
