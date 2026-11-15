<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

final readonly class RegistrationExportContext
{
    public function __construct(
        public string $eventId,
        public string $slug,
        public string $eventClass,
        public bool $canExport,
    ) {}
}
