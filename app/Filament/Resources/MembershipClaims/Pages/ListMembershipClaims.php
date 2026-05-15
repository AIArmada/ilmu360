<?php

declare(strict_types=1);

namespace App\Filament\Resources\MembershipClaims\Pages;

use App\Filament\Resources\MembershipClaims\MembershipClaimResource;
use Filament\Resources\Pages\ListRecords;

class ListMembershipClaims extends ListRecords
{
    protected static string $resource = MembershipClaimResource::class;
}
