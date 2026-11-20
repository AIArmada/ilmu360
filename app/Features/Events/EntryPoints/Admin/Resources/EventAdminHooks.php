<?php

declare(strict_types=1);

namespace App\Features\Events\EntryPoints\Admin\Resources;

use App\Features\Events\Api\Enums\EventFormat;
use App\Features\Events\Api\Enums\EventVisibility;
use App\Features\Events\Api\Enums\TimingMode;
use App\Features\Events\Api\Orm\EventCategoryCatalog;
use App\Features\Events\EntryPoints\Filament\Resources\Events\EventResource;
use App\Features\Events\EntryPoints\Http\Data\EventPayloadData;
use App\Features\Prayer\Api\PrayerReference;
use App\Platform\Api\Admin\Contracts\AdminResourceHooks;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final readonly class EventAdminHooks implements AdminResourceHooks
{
    public function supports(string $resourceClass): bool
    {
        return $resourceClass === EventResource::class;
    }

    public function filters(string $resourceClass): array
    {
        if (! $this->supports($resourceClass)) {
            return [];
        }

        return [
            [
                'key' => 'status',
                'label' => 'Status',
                'type' => 'select',
                'options' => [
                    'draft' => 'Draft',
                    'pending' => 'Pending Review',
                    'needs_changes' => 'Needs Changes',
                    'approved' => 'Approved',
                    'cancelled' => 'Cancelled',
                    'rejected' => 'Rejected',
                ],
            ],
            [
                'key' => 'visibility',
                'label' => 'Visibility',
                'type' => 'select',
                'options' => collect(EventVisibility::cases())->mapWithKeys(
                    static fn (EventVisibility $visibility): array => [$visibility->value => $visibility->getLabel()]
                )->all(),
            ],
            [
                'key' => 'event_format',
                'label' => 'Event Format',
                'type' => 'select',
                'options' => collect(EventFormat::cases())->mapWithKeys(
                    static fn (EventFormat $format): array => [$format->value => $format->getLabel()]
                )->all(),
            ],
            [
                'key' => 'event_category_ids',
                'label' => 'Event Categories',
                'type' => 'select',
                'options' => app(EventCategoryCatalog::class)->options(),
            ],
            [
                'key' => 'timing_mode',
                'label' => 'Timing Mode',
                'type' => 'select',
                'options' => collect(TimingMode::cases())->mapWithKeys(
                    static fn (TimingMode $timingMode): array => [$timingMode->value => $timingMode->label()]
                )->all(),
            ],
            [
                'key' => 'prayer_reference',
                'label' => 'Prayer Reference',
                'type' => 'select',
                'options' => collect(PrayerReference::cases())->mapWithKeys(
                    static fn (PrayerReference $reference): array => [$reference->value => $reference->label()]
                )->all(),
            ],
            [
                'key' => 'published',
                'label' => 'Published',
                'type' => 'boolean',
            ],
        ];
    }

    /**
     * @param  Builder<Model>  $query
     * @param  array<string, mixed>  $filters
     */
    public function applyFilters(Builder $query, string $resourceClass, array $filters): bool
    {
        if (! $this->supports($resourceClass) || $filters === []) {
            return false;
        }

        $model = $query->getModel();

        if (array_key_exists('status', $filters)) {
            $statuses = $this->normalizeArrayFilter($filters['status']);

            if ($statuses === []) {
                $query->whereRaw('1 = 0');

                return true;
            }

            $query->whereIn($model->qualifyColumn('status'), $statuses);
        }

        if (array_key_exists('visibility', $filters)) {
            $visibleValues = array_values(array_filter(
                array_map(static fn (string $value): ?string => EventVisibility::tryFrom($value)?->value, $this->normalizeArrayFilter($filters['visibility'])),
            ));

            if ($visibleValues === []) {
                $query->whereRaw('1 = 0');

                return true;
            }

            $query->whereIn($model->qualifyColumn('visibility'), $visibleValues);
        }

        if (array_key_exists('event_format', $filters)) {
            $formats = array_values(array_filter(
                array_map(static fn (string $value): ?string => EventFormat::tryFrom($value)?->value, $this->normalizeArrayFilter($filters['event_format'])),
            ));

            if ($formats === []) {
                $query->whereRaw('1 = 0');

                return true;
            }

            $query->whereIn($model->qualifyColumn('delivery_mode'), $formats);
        }

        if (array_key_exists('event_category_ids', $filters)) {
            $categoryIds = app(EventCategoryCatalog::class)->descendantIds($this->normalizeArrayFilter($filters['event_category_ids']));

            if ($categoryIds === []) {
                $query->whereRaw('1 = 0');

                return true;
            }

            $query->whereHas('classifications', function (Builder $classificationQuery) use ($categoryIds): void {
                $classificationQuery
                    ->whereIn('event_term_id', $categoryIds)
                    ->where('taxonomy_code', EventCategoryCatalog::TAXONOMY_CODE);
            });
        }

        if (array_key_exists('timing_mode', $filters)) {
            $timingModes = array_values(array_filter(
                array_map(static fn (string $value): ?string => TimingMode::tryFrom($value)?->value, $this->normalizeArrayFilter($filters['timing_mode'])),
            ));

            if ($timingModes === []) {
                $query->whereRaw('1 = 0');

                return true;
            }

            $query->where(function (Builder $timingQuery) use ($timingModes): void {
                if (in_array(TimingMode::PrayerRelative->value, $timingModes, true)) {
                    $timingQuery->whereHas('timeExpressions', fn (Builder $expressionQuery) => $expressionQuery->where('time_mode', TimingMode::PrayerRelative->value));
                }

                if (in_array(TimingMode::Absolute->value, $timingModes, true)) {
                    $callback = fn (Builder $expressionQuery): Builder => $expressionQuery->where('time_mode', TimingMode::PrayerRelative->value);

                    if (in_array(TimingMode::PrayerRelative->value, $timingModes, true)) {
                        $timingQuery->orWhereDoesntHave('timeExpressions', $callback);
                    } else {
                        $timingQuery->whereDoesntHave('timeExpressions', $callback);
                    }
                }
            });
        }

        if (array_key_exists('prayer_reference', $filters)) {
            $prayerReferences = array_values(array_filter(
                array_map(static fn (string $value): ?string => PrayerReference::tryFrom($value)?->value, $this->normalizeArrayFilter($filters['prayer_reference'])),
            ));

            if ($prayerReferences === []) {
                $query->whereRaw('1 = 0');

                return true;
            }

            $query->whereHas('timeExpressions', function (Builder $expressionQuery) use ($prayerReferences): void {
                $expressionQuery
                    ->where('time_mode', TimingMode::PrayerRelative->value)
                    ->where('anchor_type', 'prayer')
                    ->whereIn('anchor_code', $prayerReferences);
            });
        }

        if (array_key_exists('published', $filters)) {
            $rawPublished = $filters['published'];

            if ($rawPublished !== null && $rawPublished !== '') {
                $published = $this->normalizeBooleanFilter($rawPublished);

                if ($published === null) {
                    $query->whereRaw('1 = 0');

                    return true;
                }

                if ($published) {
                    $query->whereNotNull($model->qualifyColumn('published_at'));
                } else {
                    $query->whereNull($model->qualifyColumn('published_at'));
                }
            }
        }

        return true;
    }

    /** @return list<string> */
    public function imageUploadMcpToolKeys(): array
    {
        return ['generate_event_cover_image', 'generate_event_poster_image'];
    }

    public function imageUploadMcpTools(string $resourceClass, string $key): array
    {
        if (! $this->supports($resourceClass)) {
            return [];
        }

        return [
            'generate_event_cover_image' => [
                'tool' => 'admin-upload-event-cover-image',
                'arguments' => [
                    'event_key' => 'record',
                ],
                'prerequisite_prompt' => 'admin-event-cover-image-prompt',
            ],
            'generate_event_poster_image' => [
                'tool' => 'admin-upload-event-poster-image',
                'arguments' => [
                    'event_key' => 'record',
                ],
                'prerequisite_prompt' => 'admin-event-poster-image-prompt',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function normalizeArrayFilter(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value) && str_contains($value, ',')) {
            $value = array_map(trim(...), explode(',', $value));
        }

        $values = is_array($value) ? $value : [$value];

        return array_values(array_filter(
            array_map(static fn (mixed $item): string => (string) $item, $values),
            static fn (string $item): bool => $item !== ''
        ));
    }

    private function normalizeBooleanFilter(mixed $value): ?bool
    {
        $normalized = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        return is_bool($normalized) ? $normalized : null;
    }

    public function recordAttributes(Model $record): ?array
    {
        return $record instanceof \App\Features\Events\Models\Event
            ? EventPayloadData::fromModel($record)->toArray()
            : null;
    }

    public function recordTitle(Model $record): ?string
    {
        return $record instanceof \App\Features\Events\Models\Event
            ? (string) ($record->title ?? '')
            : null;
    }

    public function augmentAttributes(Model $record, array $attributes): array
    {
        return $attributes;
    }

    public function dateSemantics(string $resourceClass): ?array
    {
        if (! $this->supports($resourceClass)) {
            return null;
        }

        return [
            'storage_timezone' => 'UTC',
            'viewer_timezone' => 'resolved at request time',
            'local_fields' => ['starts_at_local', 'starts_on_local_date', 'ends_at_local'],
            'local_date_filter' => 'starts_on_local_date',
        ];
    }
}
