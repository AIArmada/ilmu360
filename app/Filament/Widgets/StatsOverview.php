<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\ModerationQueue;
use App\Filament\Resources\Institutions\InstitutionResource;
use App\Filament\Resources\References\ReferenceResource;
use App\Filament\Resources\Speakers\SpeakerResource;
use App\Filament\Resources\Venues\VenueResource;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Speaker;
use App\Models\Venue;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '30s';

    protected ?string $heading = 'Needs Approval';

    protected ?string $description = 'Start here. These counts lead directly to the pending review work.';

    #[\Override]
    protected function getStats(): array
    {
        $pendingEvents = Event::query()
            ->where('status', 'pending')
            ->count();

        $pendingSpeakers = Speaker::query()
            ->where('status', 'pending')
            ->count();

        $pendingInstitutions = Institution::query()
            ->where('status', 'pending')
            ->count();

        $pendingReferences = Reference::query()
            ->where('status', 'pending')
            ->count();

        $pendingVenues = Venue::query()
            ->where('status', 'pending')
            ->count();

        return [
            Stat::make('Events Needing Approval', $pendingEvents)
                ->description('Open pending moderation queue')
                ->descriptionIcon('heroicon-m-arrow-top-right-on-square')
                ->color(fn (): string => $pendingEvents > 0 ? 'warning' : 'success')
                ->url($this->moderationQueueUrl()),
            Stat::make('Speakers Needing Approval', $pendingSpeakers)
                ->description('Review pending speakers')
                ->descriptionIcon('heroicon-m-arrow-top-right-on-square')
                ->color(fn (): string => $pendingSpeakers > 0 ? 'warning' : 'success')
                ->url($this->pendingSpeakersUrl()),
            Stat::make('Institutions Needing Approval', $pendingInstitutions)
                ->description('Review pending institutions')
                ->descriptionIcon('heroicon-m-arrow-top-right-on-square')
                ->color(fn (): string => $pendingInstitutions > 0 ? 'warning' : 'success')
                ->url($this->pendingInstitutionsUrl()),
            Stat::make('References Needing Approval', $pendingReferences)
                ->description('Review pending references')
                ->descriptionIcon('heroicon-m-arrow-top-right-on-square')
                ->color(fn (): string => $pendingReferences > 0 ? 'warning' : 'success')
                ->url($this->pendingReferencesUrl()),
            Stat::make('Venues Needing Approval', $pendingVenues)
                ->description('Review pending venues')
                ->descriptionIcon('heroicon-m-arrow-top-right-on-square')
                ->color(fn (): string => $pendingVenues > 0 ? 'warning' : 'success')
                ->url($this->pendingVenuesUrl()),
        ];
    }

    protected function moderationQueueUrl(): string
    {
        return ModerationQueue::getUrl(panel: 'admin').'?tab=pending';
    }

    protected function pendingSpeakersUrl(): string
    {
        return SpeakerResource::getUrl('index', panel: 'admin').'?tableFilters[status][value]=pending';
    }

    protected function pendingInstitutionsUrl(): string
    {
        return InstitutionResource::getUrl('index', panel: 'admin').'?tableFilters[status][value]=pending';
    }

    protected function pendingReferencesUrl(): string
    {
        return ReferenceResource::getUrl('index', panel: 'admin').'?tableFilters[status][value]=pending';
    }

    protected function pendingVenuesUrl(): string
    {
        return VenueResource::getUrl('index', panel: 'admin').'?tableFilters[status][value]=pending';
    }
}
