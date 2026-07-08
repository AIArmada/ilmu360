<?php

declare(strict_types=1);

namespace App\Filament\Resources\MembershipApplications\Pages;

use App\Filament\Resources\MembershipApplications\MembershipApplicationResource;
use Filament\Resources\Pages\ListRecords;

class ListMembershipApplications extends ListRecords
{
    protected static string $resource = MembershipApplicationResource::class;
}
