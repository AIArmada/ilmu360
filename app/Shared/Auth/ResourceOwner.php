<?php

declare(strict_types=1);

namespace App\Shared\Auth;

interface ResourceOwner
{
    public function resourceOwnerType(): string;

    public function resourceOwnerId(): string;
}
