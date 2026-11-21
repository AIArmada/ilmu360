<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

interface AccountTimezoneUpdater
{
    public function updateTimezone(string $accountId, string $timezone): void;
}
