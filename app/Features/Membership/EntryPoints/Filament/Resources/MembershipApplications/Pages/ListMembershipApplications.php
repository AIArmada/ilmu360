<?php

declare(strict_types=1);

namespace App\Features\Membership\EntryPoints\Filament\Resources\MembershipApplications\Pages;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Features\Membership\EntryPoints\Filament\Resources\MembershipApplications\MembershipApplicationResource;
use Filament\Resources\Pages\ListRecords;

class ListMembershipApplications extends ListRecords
{
    public function boot(): void
    {
        OwnerContext::setForRequest(null);
    }

    #[\Override]
    protected static string $resource = MembershipApplicationResource::class;
}
