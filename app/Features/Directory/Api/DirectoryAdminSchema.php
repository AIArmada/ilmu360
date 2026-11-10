<?php

declare(strict_types=1);

namespace App\Features\Directory\Api;

/**
 * Admin form schema snapshot for one Directory resource.
 *
 * Transport details (HTTP method, endpoint, content type) stay with the admin
 * entry point; this value carries the form contract only.
 */
final readonly class DirectoryAdminSchema
{
    /**
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $rules
     * @param  array<string, mixed>  $defaults
     * @param  list<array<string, mixed>>  $catalogs
     * @param  list<array<string, mixed>>  $conditionalRules
     * @param  array<string, mixed>|null  $currentMedia
     */
    public function __construct(
        public string $resourceKey,
        public string $operation,
        public array $fields,
        public array $rules,
        public array $defaults,
        public array $catalogs = [],
        public array $conditionalRules = [],
        public ?array $currentMedia = null,
    ) {}
}
