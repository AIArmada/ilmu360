<?php

declare(strict_types=1);

namespace App\Shared\Auth;

interface UserMorphType
{
    public function morphClass(): string;
}
