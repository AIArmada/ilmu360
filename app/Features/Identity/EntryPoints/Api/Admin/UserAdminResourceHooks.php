<?php

declare(strict_types=1);

namespace App\Features\Identity\EntryPoints\Api\Admin;

use App\Features\Identity\EntryPoints\Filament\Resources\Authz\UserResource;
use App\Models\User;
use App\Platform\Api\Admin\Contracts\AdminResourceHooks;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * Keeps identity account serialization policy out of the generic engine.
 */
final class UserAdminResourceHooks implements AdminResourceHooks
{
    private const array PRIVATE_ATTRIBUTES = [
        'email',
        'email_verified_at',
        'phone',
        'phone_verified_at',
        'daily_prayer_institutions',
        'friday_prayer_institutions',
    ];

    public function supports(string $resourceClass): bool
    {
        return $resourceClass === UserResource::class;
    }

    /** @return list<array{key: string, label: string, type: string, options?: array<string, string>}> */
    public function filters(string $resourceClass): array
    {
        return [];
    }

    /** @param Builder<Model> $query */
    public function applyFilters(Builder $query, string $resourceClass, array $filters): bool
    {
        return false;
    }

    /** @return array<string, array<string, mixed>> */
    public function imageUploadMcpTools(string $resourceClass, string $key): array
    {
        return [];
    }

    /** @return list<string> */
    public function imageUploadMcpToolKeys(): array
    {
        return [];
    }

    /** @return array<string, mixed>|null */
    public function dateSemantics(string $resourceClass): ?array
    {
        return null;
    }

    /** @return array<string, mixed>|null */
    public function recordAttributes(Model $record): ?array
    {
        return null;
    }

    public function recordTitle(Model $record): ?string
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function augmentAttributes(Model $record, array $attributes): array
    {
        if (! $record instanceof User) {
            return $attributes;
        }

        return Arr::except($attributes, self::PRIVATE_ATTRIBUTES);
    }
}
