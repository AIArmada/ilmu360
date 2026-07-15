<?php

declare(strict_types=1);

namespace App\Filament\Resources\MembershipApplications\Pages;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Filament\Resources\MembershipApplications\MembershipApplicationResource;
use Filament\Resources\Pages\ListRecords;

class ListMembershipApplications extends ListRecords
{
    public function boot(): void
    {
        OwnerContext::setForRequest(null);
    }

    protected static string $resource = MembershipApplicationResource::class;
}
