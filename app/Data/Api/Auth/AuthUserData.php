<?php

namespace App\Data\Api\Auth;

use App\Models\User;
use DateTimeInterface;
use Spatie\LaravelData\Data;

class AuthUserData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $email,
        public ?string $phone,
        public ?string $timezone,
        public ?string $email_verified_at,
        public ?string $phone_verified_at,
    ) {}

    public static function fromModel(User $user): self
    {
        return new self(
            id: (string) $user->id,
            name: (string) $user->name,
            email: is_string($user->email) ? $user->email : null,
            phone: is_string($user->phone) ? $user->phone : null,
            timezone: is_string($user->timezone) ? $user->timezone : null,
            email_verified_at: $user->email_verified_at instanceof DateTimeInterface
                ? $user->email_verified_at->format(DateTimeInterface::ATOM)
                : null,
            phone_verified_at: $user->phone_verified_at instanceof DateTimeInterface
                ? $user->phone_verified_at->format(DateTimeInterface::ATOM)
                : null,
        );
    }
}
