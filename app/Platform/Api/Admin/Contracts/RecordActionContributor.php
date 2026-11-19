<?php

declare(strict_types=1);

namespace App\Platform\Api\Admin\Contracts;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Contributes surface-specific record actions for one record without the
 * platform knowing which feature owns the resource.
 */
interface RecordActionContributor
{
    public const string SURFACE_ADMIN = 'admin';

    public const string SURFACE_MEMBER = 'member';

    public function supports(string $surface, string $resourceKey, Model $record): bool;

    /**
     * @return list<array<string, mixed>>
     */
    public function actions(string $surface, string $resourceKey, Model $record, ?User $actor = null): array;
}
