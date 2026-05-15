<?php

declare(strict_types=1);

namespace App\Support\Api;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator as PaginatorContract;

final class ApiPagination
{
    public static function normalizePerPage(int $value, int $default = 20, int $max = 100): int
    {
        $normalizedDefault = max($default, 1);
        $normalizedMax = max($max, $normalizedDefault);

        if ($value < 1) {
            return $normalizedDefault;
        }

        return min($value, $normalizedMax);
    }

    /**
     * @param  LengthAwarePaginator<int, mixed>  $records
     * @return array{page: int, per_page: int, total: int, has_more: bool, next_page: int|null}
     */
    public static function paginationMeta(LengthAwarePaginator $records): array
    {
        $hasMorePages = $records->hasMorePages();

        return [
            'page' => $records->currentPage(),
            'per_page' => $records->perPage(),
            'total' => $records->total(),
            'has_more' => $hasMorePages,
            'next_page' => $hasMorePages ? $records->currentPage() + 1 : null,
        ];
    }

    /**
     * @param  PaginatorContract<int, mixed>  $records
     * @return array{page: int, per_page: int, has_more: bool, next_page: int|null}
     */
    public static function simplePaginationMeta(PaginatorContract $records): array
    {
        $hasMorePages = $records->hasMorePages();

        return [
            'page' => $records->currentPage(),
            'per_page' => $records->perPage(),
            'has_more' => $hasMorePages,
            'next_page' => $hasMorePages ? $records->currentPage() + 1 : null,
        ];
    }
}
