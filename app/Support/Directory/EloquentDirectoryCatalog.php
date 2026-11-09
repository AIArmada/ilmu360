<?php

declare(strict_types=1);

namespace App\Support\Directory;

use App\Models\User;
use App\Shared\Directory\DirectoryCatalog;
use App\Shared\Directory\DirectoryOption;
use App\Support\Api\Frontend\FrontendCatalogService;

/**
 * Interim Directory catalog adapter over the current catalog queries.
 *
 * Directory will expose these reads from its own feature once extracted. This
 * adapter keeps current query behavior and provides a model-free boundary.
 */
final readonly class EloquentDirectoryCatalog implements DirectoryCatalog
{
    public function __construct(
        private FrontendCatalogService $catalogs,
    ) {}

    public function institutions(?string $accountId = null, ?string $search = null, int $limit = 50): array
    {
        return $this->options(
            $this->catalogs->submitInstitutions(
                $this->account($accountId),
                $search,
                $this->normalizeLimit($limit),
            ),
        );
    }

    public function persons(?string $accountId = null, ?string $search = null, int $limit = 50): array
    {
        return $this->options(
            $this->catalogs->submitPersons(
                $this->account($accountId),
                $search,
                $this->normalizeLimit($limit),
            ),
        );
    }

    public function venues(?string $search = null, int $limit = 50): array
    {
        return $this->options(
            $this->catalogs->venues($search, $this->normalizeLimit($limit)),
        );
    }

    public function references(?string $search = null, int $limit = 50): array
    {
        return $this->options(
            $this->catalogs->references($search, $this->normalizeLimit($limit)),
        );
    }

    private function account(?string $accountId): ?User
    {
        if ($accountId === null || $accountId === '') {
            return null;
        }

        return User::query()->find($accountId);
    }

    private function normalizeLimit(int $limit): int
    {
        return max(1, $limit);
    }

    /**
     * @param  list<array{id: string, label: string}>  $options
     * @return list<DirectoryOption>
     */
    private function options(array $options): array
    {
        return array_map(
            static fn (array $option): DirectoryOption => new DirectoryOption(
                id: (string) $option['id'],
                label: (string) $option['label'],
            ),
            $options,
        );
    }
}
