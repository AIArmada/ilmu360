<?php

namespace App\Filament\Resources\Reports\Support;

use App\Filament\Resources\DonationChannels\DonationChannelResource;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Institutions\InstitutionResource;
use App\Filament\Resources\References\ReferenceResource;
use App\Filament\Resources\Speakers\SpeakerResource;
use App\Models\DonationChannel;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Report;
use App\Models\Speaker;

class ReportPresenter
{
    public static function entityTitle(Report $report): string
    {
        $entity = $report->entity;
        $fallbackTitle = (string) $report->entity_id;

        return match (true) {
            $entity instanceof Event => filled($entity->title) ? $entity->title : $fallbackTitle,
            $entity instanceof Institution => filled($entity->name) ? $entity->name : $fallbackTitle,
            $entity instanceof Speaker => filled($entity->formatted_name) ? $entity->formatted_name : $fallbackTitle,
            $entity instanceof Reference => filled($entity->title) ? $entity->title : $fallbackTitle,
            $entity instanceof DonationChannel => filled($entity->label)
                ? $entity->label
                : (filled($entity->account_name) ? $entity->account_name : $fallbackTitle),
            default => $fallbackTitle,
        };
    }

    public static function entityAdminUrl(Report $report): ?string
    {
        $entity = $report->entity;

        return match (true) {
            $entity instanceof Event => EventResource::getUrl('view', ['record' => $entity], panel: 'admin'),
            $entity instanceof Institution => InstitutionResource::getUrl('edit', ['record' => $entity], panel: 'admin'),
            $entity instanceof Speaker => SpeakerResource::getUrl('edit', ['record' => $entity], panel: 'admin'),
            $entity instanceof Reference => ReferenceResource::getUrl('edit', ['record' => $entity], panel: 'admin'),
            $entity instanceof DonationChannel => DonationChannelResource::getUrl('edit', ['record' => $entity], panel: 'admin'),
            default => null,
        };
    }
}
