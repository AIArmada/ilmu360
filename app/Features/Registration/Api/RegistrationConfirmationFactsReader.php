<?php

declare(strict_types=1);

namespace App\Features\Registration\Api;

use App\Features\Registration\Api\Data\RegistrationConfirmationFacts;

interface RegistrationConfirmationFactsReader
{
    public function find(string $registrationId): ?RegistrationConfirmationFacts;
}
