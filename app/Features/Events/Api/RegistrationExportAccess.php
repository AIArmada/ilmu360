<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

interface RegistrationExportAccess
{
    public function resolve(string $eventId): ?RegistrationExportContext;
}
