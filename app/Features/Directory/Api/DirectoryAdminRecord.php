<?php

declare(strict_types=1);

namespace App\Features\Directory\Api;

/**
 * Admin record snapshot for one Directory record.
 */
final readonly class DirectoryAdminRecord
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, bool>  $abilities
     * @param  array<string, string|null>  $panelRoutes
     */
    public function __construct(
        public string $resourceKey,
        public string $id,
        public string $routeKey,
        public ?string $title,
        public array $attributes,
        public array $abilities,
        public array $panelRoutes,
    ) {}
}
