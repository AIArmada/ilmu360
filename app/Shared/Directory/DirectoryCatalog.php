<?php

declare(strict_types=1);

namespace App\Shared\Directory;

/**
 * Selectable Directory records for forms and submission workflows.
 */
interface DirectoryCatalog
{
    /**
     * @return list<DirectoryOption>
     */
    public function institutions(?string $accountId = null, ?string $search = null, int $limit = 50): array;

    /**
     * @return list<DirectoryOption>
     */
    public function persons(?string $accountId = null, ?string $search = null, int $limit = 50): array;

    /**
     * @return list<DirectoryOption>
     */
    public function venues(?string $search = null, int $limit = 50): array;

    /**
     * @return list<DirectoryOption>
     */
    public function references(?string $search = null, int $limit = 50): array;
}
