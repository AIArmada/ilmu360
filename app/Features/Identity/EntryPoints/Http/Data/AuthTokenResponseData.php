<?php

declare(strict_types=1);

namespace App\Features\Identity\EntryPoints\Http\Data;

use Spatie\LaravelData\Data;

class AuthTokenResponseData extends Data
{
    public function __construct(
        public string $message,
        public AuthTokenData $data,
    ) {}
}
