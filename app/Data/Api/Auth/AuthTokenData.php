<?php

namespace App\Data\Api\Auth;

use App\Models\User;
use Spatie\LaravelData\Data;

class AuthTokenData extends Data
{
    public function __construct(
        public string $access_token,
        public string $token_type,
        public AuthUserData $user,
    ) {}

    public static function fromToken(string $accessToken, User $user): self
    {
        return new self(
            access_token: $accessToken,
            token_type: 'Bearer',
            user: AuthUserData::fromModel($user),
        );
    }
}
