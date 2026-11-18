<?php

declare(strict_types=1);

namespace App\Features\References\Services;

use App\Features\References\Api\ReferenceCatalog;
use App\Features\References\EntryPoints\Filament\Forms\ReferenceAuthorFormSchema;
use App\Features\References\Models\Reference;
use Illuminate\Database\Eloquent\Builder;

final readonly class EloquentReferenceCatalog implements ReferenceCatalog
{
    public function references(?string $search = null, int $limit = 50): array
    {
        $query = Reference::query()->active()->with('parentReference.parentReference')->orderBy('title');
        $normalizedSearch = trim((string) $search);

        if ($normalizedSearch !== '') {
            $query->where(function (Builder $referenceQuery) use ($normalizedSearch): void {
                $referenceQuery
                    ->whereLike('title', '%'.$normalizedSearch.'%')
                    ->orWherePartTextLike('%'.$normalizedSearch.'%')
                    ->orWhereLike('edition_label', '%'.$normalizedSearch.'%')
                    ->orWhereLike('publisher', '%'.$normalizedSearch.'%')
                    ->orWhereLike('isbn', '%'.$normalizedSearch.'%');
            });
        }

        return $query
            ->limit($limit)
            ->get(['id', 'title', 'parent_id', 'record_kind', 'reference_parts', 'metadata', 'edition_number', 'edition_label', 'publisher', 'year'])
            ->map(fn (Reference $reference): array => [
                'id' => (string) $reference->id,
                'label' => $reference->displayTitle(),
            ])
            ->all();
    }

    public function authors(?string $search = null, int $limit = 50): array
    {
        $items = [];

        foreach (ReferenceAuthorFormSchema::searchOptions(trim((string) $search), $limit) as $id => $label) {
            $items[] = ['id' => $id, 'label' => $label];
        }

        return $items;
    }
}
