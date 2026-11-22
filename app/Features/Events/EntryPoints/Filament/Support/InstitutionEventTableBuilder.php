<?php

declare(strict_types=1);

namespace App\Features\Events\EntryPoints\Filament\Support;

use App\Features\Events\Api\InstitutionEventTable;
use App\Features\Events\Models\Event;
use App\Features\Places\Api\SpaceLocation;
use App\Platform\Time\UserDateTimeFormatter;
use BackedEnum;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * Event-owned Filament table composition.
 *
 * Institutions passes only scalar scope and presentation state; Eloquent
 * builders and event models never cross the feature API.
 */
final readonly class InstitutionEventTableBuilder implements InstitutionEventTable
{
    public function configure(
        Table $table,
        ?string $institutionId,
        ?string $selectedInstitutionId,
        bool $canUseSelectedInstitutionForScopedSubmission,
        string $sort,
        bool $hasExplicitTableSort,
        array $statusOptions,
        array $visibilityOptions,
    ): Table {
        return $table
            ->query(static function () use ($institutionId): Builder {
                if ($institutionId === null) {
                    return Event::query()->whereRaw('1 = 0');
                }

                return Event::query()
                    ->where('institution_id', $institutionId)
                    ->with([
                        'primaryLocation.venueSpace:id,name',
                        'persons:id,name',
                        'references.parentReference.parentReference',
                    ])
                    ->withCount(['registrations as dashboard_registrations_count']);
            })
            ->columns([
                TextColumn::make('title')
                    ->label(__('Title'))
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $search = '%'.trim($search).'%';

                        return $query->where(function (Builder $builder) use ($search): void {
                            $builder
                                ->whereLike('title', $search)
                                ->orWhereHas('venue', fn (Builder $venueQuery) => $venueQuery->whereLike('name', $search));
                        });
                    })
                    ->sortable()
                    ->html()
                    ->wrap()
                    ->formatStateUsing(fn (Event $record): HtmlString => new HtmlString(view(
                        'livewire.pages.dashboard.partials.institution-event-title-cell',
                        [
                            'event' => $record,
                            'canUseSelectedInstitutionForScopedSubmission' => $canUseSelectedInstitutionForScopedSubmission,
                            'selectedInstitutionId' => $selectedInstitutionId,
                        ],
                    )->render())),
                TextColumn::make('starts_at')
                    ->label(__('Date'))
                    ->state(fn (Event $record): string => $this->formatEventSchedule($record))
                    ->sortable()
                    ->wrap(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $this->translateStatusLabel($state))
                    ->color(fn (string $state): string => match ($state) {
                        'approved' => 'success',
                        'pending' => 'warning',
                        'needs_changes' => 'info',
                        'rejected', 'cancelled' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('person_names')
                    ->label(__('Speakers'))
                    ->state(fn (Event $record): array => $record->persons
                        ->pluck('name')
                        ->filter(fn (mixed $name): bool => is_string($name) && trim($name) !== '')
                        ->map(fn (string $name): string => trim($name))
                        ->values()
                        ->all())
                    ->listWithLineBreaks()
                    ->limitList(2)
                    ->expandableLimitedList()
                    ->placeholder('-')
                    ->wrap()
                    ->tooltip(fn (Event $record): ?string => $record->persons
                        ->pluck('name')
                        ->filter(fn (mixed $name): bool => is_string($name) && trim($name) !== '')
                        ->map(fn (string $name): string => trim($name))
                        ->implode(', ') ?: null),
                TextColumn::make('reference_titles')
                    ->label(__('References'))
                    ->state(fn (Event $record): array => $record->references
                        ->pluck('title')
                        ->filter(fn (mixed $title): bool => is_string($title) && trim($title) !== '')
                        ->map(fn (string $title): string => trim($title))
                        ->values()
                        ->all())
                    ->listWithLineBreaks()
                    ->limitList(2)
                    ->expandableLimitedList()
                    ->placeholder('-')
                    ->wrap()
                    ->tooltip(fn (Event $record): ?string => $record->references
                        ->pluck('title')
                        ->filter(fn (mixed $title): bool => is_string($title) && trim($title) !== '')
                        ->map(fn (string $title): string => trim($title))
                        ->implode(', ') ?: null),
                TextColumn::make('primaryLocation.venueSpace.name')
                    ->label(__('Location'))
                    ->state(fn (Event $record): ?string => app(SpaceLocation::class)->name(
                        (string) ($record->primaryLocation->space_name_snapshot ?? ''),
                        $record->primaryLocation?->venueSpace?->name,
                    ))
                    ->placeholder('-')
                    ->wrap(),
                TextColumn::make('dashboard_registrations_count')
                    ->label(__('Registrations'))
                    ->numeric()
                    ->sortable()
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('visibility')
                    ->label(__('Visibility'))
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => $this->translateStatusLabel($state))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('published_at')
                    ->label(__('Published At'))
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort(fn (Builder $query): string|Builder|null => $this->applyDashboardEventSort($query, $sort, $hasExplicitTableSort), fn (): string => $this->dashboardEventSortDirection($sort))
            ->searchPlaceholder(__('Search by event title or venue'))
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(collect($statusOptions)->except('all')->all()),
                SelectFilter::make('visibility')
                    ->label(__('Visibility'))
                    ->options(collect($visibilityOptions)->except('all')->all()),
            ])
            ->deferFilters(false)
            ->filtersFormColumns(2)
            ->filtersFormWidth(Width::Medium)
            ->paginated([8, 15, 25])
            ->paginationMode(PaginationMode::Default)
            ->defaultPaginationPageOption(8)
            ->extremePaginationLinks()
            ->recordClasses(fn (Event $record): ?string => (string) $record->status === 'pending' ? 'bg-amber-50/80' : null)
            ->stackedOnMobile()
            ->scrollToTopOnPageChange()
            ->emptyStateHeading(__('No events match the current filters.'))
            ->emptyStateDescription(__('Try adjusting the search or filters.'));
    }

    private function translateStatusLabel(mixed $status): string
    {
        if ($status instanceof BackedEnum) {
            if (method_exists($status, 'getLabel')) {
                return (string) $status->getLabel();
            }

            if (method_exists($status, 'label')) {
                return (string) $status->label();
            }

            $status = $status->value;
        }

        if (! is_scalar($status)) {
            return '';
        }

        $status = (string) $status;
        $translated = __($status);

        if ($translated !== $status) {
            return $translated;
        }

        return str($status)->replace('_', ' ')->headline()->toString();
    }

    private function formatEventSchedule(Event $event): string
    {
        if (! $event->starts_at) {
            return __('TBC');
        }

        $date =
            UserDateTimeFormatter::translatedFormat($event->starts_at, 'd M Y');
        $time = $event->isPrayerRelative()
            ? (string) $event->timing_display
            : UserDateTimeFormatter::translatedFormat($event->starts_at, 'h:i A');

        return $date.', '.$time;
    }

    /**
     * Apply dashboard event sort when Filament column sort is not active.
     *
     * @param  Builder<Event>  $query
     * @return Builder<Event>|string|null
     */
    private function applyDashboardEventSort(Builder $query, string $sort, bool $hasExplicitTableSort): Builder|string|null
    {
        if ($hasExplicitTableSort) {
            return null;
        }

        return match ($sort) {
            'starts_asc', 'starts_desc' => 'starts_at',
            'title_asc', 'title_desc' => 'title',
            'registrations_desc' => 'dashboard_registrations_count',
            'pending_first' => $query
                ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
                ->orderBy('starts_at')
                ->orderBy('title'),
            default => 'starts_at',
        };
    }

    private function dashboardEventSortDirection(string $sort): string
    {
        return match ($sort) {
            'starts_asc', 'title_asc', 'pending_first' => 'asc',
            default => 'desc',
        };
    }
}
