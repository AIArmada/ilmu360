<?php

declare(strict_types=1);

namespace App\Platform\Auth;

use App\Models\User;
use App\Shared\Auth\UserMorphType;

final class EloquentUserMorphType implements UserMorphType
{
    public function morphClass(): string
    {
        return (new User)->getMorphClass();
    }
}
