<?php

declare(strict_types=1);

namespace App\Shared\Auth;

/** Profile fields accepted from account-settings surfaces. */
final readonly class AccountProfileUpdate
{
    public function __construct(
        public string $name,
        public ?string $email,
        public ?string $phone,
        public ?string $timezone,
        public ?string $gender = null,
        public ?string $dateOfBirth = null,
        public ?string $password = null,
        public bool $genderProvided = false,
        public bool $dateOfBirthProvided = false,
    ) {}
}
