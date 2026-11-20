<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/**
 * Admin/MCP event search boundary.
 *
 * Exposes the same normalization, validation and payload as the MCP
 * `admin-search-events` tool.
 */
interface AdminEventSearch
{
    /**
     * Comma-separated filter keys normalized before validation.
     *
     * @var list<string>
     */
    public const array ARRAY_FILTER_KEYS = [
        'language_codes',
        'event_category_ids',
        'age_group',
        'event_format',
        'person_ids',
        'key_person_roles',
        'person_in_charge_ids',
        'moderator_ids',
        'imam_ids',
        'khatib_ids',
        'bilal_ids',
        'discipline_tag_ids',
        'domain_tag_ids',
        'source_tag_ids',
        'issue_tag_ids',
        'reference_ids',
        'reference_author_search',
    ];

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{
     *   data: list<string>,
     *   meta: array{
     *     search: array{
     *       query: string|null,
     *       sort: 'time',
     *       nearby: array{enabled: bool, lat: float|null, lng: float|null, radius_km: mixed},
     *       filters: array<int, mixed>
     *     },
     *     pagination: array{
     *       page: int, per_page: int, total: int, last_page: int,
     *       from: int|null, to: int|null, has_more_pages: bool
     *     }
     *   }
     * }
     */
    public function search(array $arguments): array;

    /**
     * @return array<string, mixed>
     */
    public function validationRules(): array;
}
