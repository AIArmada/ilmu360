<?php

declare(strict_types=1);

namespace App\Features\References\EntryPoints\Admin\Resources;

use App\Platform\Api\Admin\Contracts\AdminResourceHooks;
use App\Shared\Cache\SelectionCatalog;
use Illuminate\Database\Eloquent\Model;

final readonly class ReferenceAdminHooks implements AdminResourceHooks
{
    public function supports(string $resourceClass): bool
    {
        return $resourceClass === \App\Features\References\EntryPoints\Filament\Resources\References\ReferenceResource::class;
    }

    public function filters(string $resourceClass): array
    {
        return [];
    }

    public function applyFilters(\Illuminate\Database\Eloquent\Builder $query, string $resourceClass, array $filters): bool
    {
        return false;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function imageUploadMcpTools(string $resourceClass, string $key): array
    {
        return [];
    }

    public function imageUploadMcpToolKeys(): array
    {
        return [];
    }

    public function dateSemantics(string $resourceClass): ?array
    {
        return null;
    }

    public function recordAttributes(Model $record): ?array
    {
        return null;
    }

    public function recordTitle(Model $record): ?string
    {
        return null;
    }

    public function augmentAttributes(Model $record, array $attributes): array
    {
        if (! $record instanceof \App\Features\References\Models\Reference) {
            return $attributes;
        }

        $authors = $record->effectiveAuthorsStructured();
        $attributes['authors'] = $authors;
        $attributes['author_ids'] = array_column($authors, 'id');
        $attributes['language_label'] = app(SelectionCatalog::class)->languageLabel($record->language);

        unset($attributes['authorLinks'], $attributes['contributors']);

        return $attributes;
    }
}
