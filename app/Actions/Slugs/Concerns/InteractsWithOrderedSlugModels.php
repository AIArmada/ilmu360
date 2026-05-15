<?php

namespace App\Actions\Slugs\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

trait InteractsWithOrderedSlugModels
{
    /**
     * @template TModel of Model
     *
     * @param  Collection<int, TModel>  $records
     * @param  callable(TModel): bool  $syncModel
     */
    protected function syncOrderedModels(Collection $records, callable $syncModel): bool
    {
        $didChange = false;

        foreach ($this->orderedModels($records) as $record) {
            $didChange = $syncModel($record) || $didChange;
        }

        return $didChange;
    }

    /**
     * @template TModel of Model
     *
     * @param  Collection<int, TModel>  $matchingRecords
     */
    protected function existingModelSequence(Collection $matchingRecords, string $modelId): ?int
    {
        $existingIndex = $this->orderedModels($matchingRecords)->search(
            fn (Model $record): bool => (string) $record->getKey() === $modelId,
        );

        if (! is_int($existingIndex)) {
            return null;
        }

        return $existingIndex + 1;
    }

    /**
     * @template TModel of Model
     *
     * @param  Collection<int, TModel>  $records
     * @return Collection<int, TModel>
     */
    protected function orderedModels(Collection $records): Collection
    {
        return $records
            ->sort(function (Model $left, Model $right): int {
                $leftCreatedAt = $this->createdAtTimestamp($left);
                $rightCreatedAt = $this->createdAtTimestamp($right);

                if ($leftCreatedAt !== $rightCreatedAt) {
                    return $leftCreatedAt <=> $rightCreatedAt;
                }

                return strcmp((string) $left->getKey(), (string) $right->getKey());
            })
            ->values();
    }

    private function createdAtTimestamp(Model $model): int
    {
        $createdAt = $model->getAttribute('created_at');

        return $createdAt instanceof \DateTimeInterface
            ? $createdAt->getTimestamp()
            : 0;
    }
}
