<?php

declare(strict_types=1);

namespace App\Features\Identity\EntryPoints\Fortify;

use App\Features\Identity\Actions\RegisterNewAccountAction;
use App\Models\User;
use Laravel\Fortify\Contracts\CreatesNewUsers;

final readonly class CreateNewUser implements CreatesNewUsers
{
    public function __construct(private RegisterNewAccountAction $registration) {}

    /** @param array<string, mixed> $input */
    public function create(array $input): User
    {
        return $this->registration->handle($input);
    }
}
