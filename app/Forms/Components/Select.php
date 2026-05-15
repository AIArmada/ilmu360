<?php

namespace App\Forms\Components;

use Closure;
use Filament\Forms\Components\Select as FilamentSelect;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;

class Select extends FilamentSelect
{
    public function closeOnSelect(bool $condition = true): static
    {
        if ($condition) {
            $this->extraAttributes([
                'x-close-on-select' => true,
            ]);
        }

        return $this;
    }

    public function quickAdd(bool $condition = true, string|Closure|null $label = null): static
    {
        if (! $condition) {
            return $this;
        }

        $searchResultsResolver = $this->getSearchResultsUsing;
        $optionLabelsResolver = $this->getOptionLabelsUsing;

        $this->live();

        $this->getSearchResultsUsing(function (Select $component, ?string $search) use ($searchResultsResolver, $label): array {
            $results = $component->evaluateQuickAddSearchResultsResolver($searchResultsResolver, $search);
            $search = trim((string) $search);

            if ($search === '' || ! $component->hasRelationship() || $component->quickAddResultsContainExactMatch($results, $search)) {
                return $results;
            }

            return [
                $component->buildQuickAddState($search) => $component->resolveQuickAddLabel($search, $label),
                ...$results,
            ];
        });

        $this->afterStateUpdated(function (Select $component, mixed $state): void {
            $component->replaceQuickAddStateWithCreatedRecords($state);
        });

        if ($optionLabelsResolver instanceof Closure) {
            $this->getOptionLabelsUsing(function (Select $component, array $values) use ($optionLabelsResolver): array {
                $values = array_values(array_filter(
                    $values,
                    fn (mixed $value): bool => ! $component->isQuickAddState($value),
                ));

                if ($values === []) {
                    return [];
                }

                return $component->evaluateQuickAddOptionLabelsResolver($optionLabelsResolver, $values);
            });
        }

        return $this;
    }

    /**
     * @return array<string, string>
     */
    protected function evaluateQuickAddSearchResultsResolver(?Closure $resolver, ?string $search): array
    {
        if (! $resolver instanceof Closure) {
            return [];
        }

        $results = $this->evaluate($resolver, [
            'query' => $search,
            'search' => $search,
            'searchQuery' => $search,
        ]);

        if ($results instanceof Arrayable) {
            $results = $results->toArray();
        }

        return is_array($results) ? $results : [];
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<string, string>
     */
    protected function evaluateQuickAddOptionLabelsResolver(Closure $resolver, array $values): array
    {
        $labels = $this->evaluate($resolver, [
            'values' => $values,
        ]);

        if ($labels instanceof Arrayable) {
            $labels = $labels->toArray();
        }

        return is_array($labels) ? $labels : [];
    }

    /**
     * @param  array<string, mixed>  $results
     */
    protected function quickAddResultsContainExactMatch(array $results, string $search): bool
    {
        $normalizedSearch = $this->normalizeQuickAddLabel($search);

        return array_any($results, fn ($value) => $this->normalizeQuickAddLabel($value) === $normalizedSearch);
    }

    protected function resolveQuickAddLabel(string $search, string|Closure|null $label): string
    {
        if ($label instanceof Closure) {
            return (string) $this->evaluate($label, [
                'query' => $search,
                'search' => $search,
                'searchQuery' => $search,
                'term' => $search,
            ]);
        }

        $template = $label ?? __('quick_add.add', ['term' => $search]);

        return str_replace(['{search}', ':term'], $search, $template);
    }

    protected function replaceQuickAddStateWithCreatedRecords(mixed $state): void
    {
        if (blank($state) || ! $this->hasRelationship()) {
            return;
        }

        if (is_array($state)) {
            $didReplace = false;
            $newState = [];

            foreach ($state as $value) {
                if ($this->isQuickAddState($value)) {
                    $newState[] = $this->createQuickAddRecord($value);
                    $didReplace = true;

                    continue;
                }

                $newState[] = $value;
            }

            if ($didReplace) {
                $this->state($newState);
            }

            return;
        }

        if ($this->isQuickAddState($state)) {
            $this->state($this->createQuickAddRecord($state));
        }
    }

    protected function createQuickAddRecord(string $state): string
    {
        $relationship = $this->getRelationship();
        $titleAttribute = $this->getRelationshipTitleAttribute();
        $search = $this->extractQuickAddSearch($state);
        $relatedModel = $this->resolveQuickAddRelatedModel($relationship);

        if (! $relatedModel || blank($titleAttribute) || blank($search)) {
            return $state;
        }

        $record = $relatedModel->newInstance();
        $record->fill([
            $titleAttribute => $search,
        ]);
        $record->save();

        return (string) $record->getKey();
    }

    protected function buildQuickAddState(string $search): string
    {
        return '__quick_add__'.$search;
    }

    protected function extractQuickAddSearch(string $state): string
    {
        return trim(substr($state, strlen('__quick_add__')));
    }

    protected function isQuickAddState(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, '__quick_add__');
    }

    protected function normalizeQuickAddLabel(mixed $value): string
    {
        return mb_strtolower(trim(html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    protected function resolveQuickAddRelatedModel(mixed $relationship): ?Model
    {
        if (! is_object($relationship) || ! method_exists($relationship, 'getRelated')) {
            return null;
        }

        $relatedModel = $relationship->getRelated();

        return $relatedModel instanceof Model ? $relatedModel : null;
    }
}
