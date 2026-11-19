<?php

declare(strict_types=1);

namespace App\Features\Identity\Support\Telemetry;

use App\Platform\Telemetry\Contracts\SignalSchemaContributor;

final class IdentitySignalSchemaContributor implements SignalSchemaContributor
{
    public function supports(string $event): bool
    {
        return str_starts_with($event, 'auth.');
    }

    /**
     * @return array<string, list<string>>
     */
    public function schemas(): array
    {
        return [
            'auth.login' => ['method', 'created_account'],
            'auth.signup.completed' => ['has_email', 'has_phone'],
            'auth.password_reset.completed' => [],
            'auth.email_verified' => [],
        ];
    }
}
