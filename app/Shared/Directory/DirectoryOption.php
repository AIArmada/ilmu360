<?php

declare(strict_types=1);

namespace App\Shared\Directory;

final readonly class DirectoryOption
{
    public function __construct(
        public string $id,
        public string $label,
    ) {}
}
