<?php

declare(strict_types=1);

namespace App\Features\Identity\EntryPoints\Http\Data;

use App\Shared\Auth\AccountProfile;
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

    public static function fromProfile(AccountProfile $profile): self
    {
        return new self(
            id: $profile->id,
            name: $profile->name,
            email: $profile->email,
            phone: $profile->phone,
            timezone: $profile->rawTimezone,
            email_verified_at: $profile->emailVerifiedAt?->format(DateTimeInterface::ATOM),
            phone_verified_at: $profile->phoneVerifiedAt?->format(DateTimeInterface::ATOM),
        );
    }
}
