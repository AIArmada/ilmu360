<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

interface SearchEngineResolver
{
    public function activeDriver(): string;

    public function usesScout(): bool;

    public function usesTypesense(): bool;

    public function isRemoteEngineAvailable(): bool;

    public function resultLimit(): int;
}
