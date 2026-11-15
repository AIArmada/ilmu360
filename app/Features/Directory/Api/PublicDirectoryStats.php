<?php

declare(strict_types=1);

namespace App\Features\Directory\Api;

interface PublicDirectoryStats
{
    public function activePersonCount(): int;

    public function activeInstitutionCount(): int;

    public function rootReferenceCount(): int;

    public function forgetCachedCounts(): void;
}
