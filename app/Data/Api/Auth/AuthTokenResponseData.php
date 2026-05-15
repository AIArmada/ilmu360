<?php

declare(strict_types=1);

namespace App\Data\Api\Auth;

use Spatie\LaravelData\Data;

class AuthTokenResponseData extends Data
{
    public function __construct(
        public string $message,
        public AuthTokenData $data,
    ) {}
}
