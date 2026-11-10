<?php

declare(strict_types=1);

namespace App\Features\Identity\EntryPoints\Http\Data;

use App\Shared\Auth\AccountProfile;
use Spatie\LaravelData\Data;

class AuthTokenData extends Data
{
    public function __construct(
        public string $access_token,
        public string $token_type,
        public AuthUserData $user,
    ) {}

    public static function fromToken(string $accessToken, AccountProfile $profile): self
    {
        return new self(
            access_token: $accessToken,
            token_type: 'Bearer',
            user: AuthUserData::fromProfile($profile),
        );
    }
}
