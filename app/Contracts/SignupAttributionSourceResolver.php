<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Support\Auth\SignupAttributionSource;
use Illuminate\Http\Request;

interface SignupAttributionSourceResolver
{
    public function resolve(?Request $request): ?SignupAttributionSource;
}
