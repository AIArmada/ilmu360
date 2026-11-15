<?php

declare(strict_types=1);

namespace App\Features\Contributions\Api;

final readonly class InstitutionMapsSelectionContext
{
    private const string CREATE_CACHE_PREFIX = 'institution-contribution:verified-maps:';

    private const string UPDATE_CACHE_PREFIX = 'institution-contribution-update:verified-maps:';

    private function __construct(private string $cachePrefix) {}

    public static function create(): self
    {
        return new self(self::CREATE_CACHE_PREFIX);
    }

    public static function update(): self
    {
        return new self(self::UPDATE_CACHE_PREFIX);
    }

    public function cachePrefix(): string
    {
        return $this->cachePrefix;
    }
}
