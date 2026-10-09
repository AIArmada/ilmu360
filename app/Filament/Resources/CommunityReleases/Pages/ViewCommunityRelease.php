<?php

declare(strict_types=1);

namespace App\Filament\Resources\CommunityReleases\Pages;

use App\Filament\Resources\CommunityReleases\CommunityReleaseResource;
use Filament\Resources\Pages\ViewRecord;

class ViewCommunityRelease extends ViewRecord
{
    protected static string $resource = CommunityReleaseResource::class;
}
