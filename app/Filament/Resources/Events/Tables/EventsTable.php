<?php

namespace App\Filament\Resources\Events\Tables;

use App\Actions\Events\GenerateEventCoverImageAction;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventPrayerTime;
use App\Enums\EventStructure;
use App\Enums\EventType;
use App\Enums\EventVisibility;
use App\Enums\PrayerReference;
use App\Enums\TimingMode;
use App\Filament\Resources\Institutions\InstitutionResource;
use App\Models\Event;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\SpatieMediaLibraryImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

class EventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                SpatieMediaLibraryImageColumn::make('cover')
                    ->label('Cover')
                    ->collection('cover')
                    ->conversion('thumb')
                    ->square()
                    ->size(56),
                TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Event $record): ?string => $record->parentEvent?->title),
                TextColumn::make('event_structure')
                    ->label('Structure')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => self::formatEnumValue($state, EventStructure::class))
                    ->sortable(),
                TextColumn::make('institution.name')
                    ->sortable()
                    ->searchable()
                    ->url(function (Event $record): ?string {
                        if (! $record->institution) {
                            return null;
                        }

                        return InstitutionResource::getUrl('edit', ['record' => $record->institution->id]);
                    }),
                TextColumn::make('event_type')
                    ->label('Type')
                    ->formatStateUsing(fn (mixed $state): string => self::formatEnumCollection($state, EventType::class))
                    ->wrap()
                    ->searchable(query: function ($query, string $search) {
                        $eventType = EventType::tryFrom($search);

                        return $query->whereJsonContains('event_type', $eventType ? $eventType->value : $search);
                    }),
                TextColumn::make('starts_at')
                    ->dateTime()
                    ->description(fn (Event $record): ?string => $record->timing_mode === TimingMode::PrayerRelative ? $record->prayer_display_text : null)
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (mixed $state): string => match ((string) $state) {
                        'pending' => 'warning',
                        'approved' => 'success',
                        'needs_changes' => 'info',
                        'cancelled' => 'danger',
                        'rejected' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (mixed $state): string => match ((string) $state) {
                        'draft' => 'Draft',
                        'pending' => 'Pending Review',
                        'needs_changes' => 'Needs Changes',
                        'approved' => 'Approved',
                        'cancelled' => 'Cancelled',
                        'rejected' => 'Rejected',
                        default => (string) $state,
                    })
                    ->sortable(),
                TextColumn::make('latestPublishedChangeAnnouncement.type')
                    ->label('Latest Notice')
                    ->badge()
                    ->color(fn (mixed $state): string => match ($state instanceof BackedEnum ? $state->value : (string) $state) {
                        'cancelled' => 'danger',
                        'postponed',
                        'rescheduled_earlier',
                        'rescheduled_later',
                        'schedule_changed' => 'warning',
                        'location_changed',
                        'replacement_linked' => 'info',
                        default => filled($state) ? 'gray' : 'gray',
                    })
                    ->formatStateUsing(fn (mixed $state): string => match ($state instanceof BackedEnum ? $state->value : (string) $state) {
                        'cancelled' => 'Cancelled',
                        'postponed' => 'Postponed',
                        'rescheduled_earlier' => 'Earlier',
                        'rescheduled_later' => 'Later',
                        'schedule_changed' => 'Schedule',
                        'location_changed' => 'Location',
                        'speaker_changed' => 'Speaker',
                        'topic_changed' => 'Topic',
                        'reference_changed' => 'Reference',
                        'organizer_changed' => 'Organizer',
                        'replacement_linked' => 'Replacement',
                        'other' => 'Updated',
                        default => 'None',
                    })
                    ->placeholder('None')
                    ->toggleable(),
                TextColumn::make('visibility')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => self::formatEnumValue($state, EventVisibility::class))
                    ->sortable(),
                TextColumn::make('event_format')
                    ->label('Format')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => self::formatEnumValue($state, EventFormat::class))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('gender')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => self::formatEnumValue($state, EventGenderRestriction::class))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('age_group')
                    ->label('Age Group')
                    ->formatStateUsing(fn (mixed $state): string => self::formatEnumCollection($state, EventAgeGroup::class))
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                ToggleColumn::make('is_featured')
                    ->label('Featured')
                    ->visible(fn (): bool => self::canManageFeaturedFlag()),
                ToggleColumn::make('is_active')
                    ->label('Active'),
                IconColumn::make('is_muslim_only')
                    ->boolean()
                    ->label('Muslim Only')
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('settings.registration_required')
                    ->boolean()
                    ->label('Reg?'),
                TextColumn::make('event_url')
                    ->label('Event URL')
                    ->url(fn (Event $record): ?string => $record->event_url)
                    ->openUrlInNewTab()
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('live_url')
                    ->label('Live URL')
                    ->url(fn (Event $record): ?string => $record->live_url)
                    ->openUrlInNewTab()
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('submitter.email')
                    ->label('Submitter')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('published_at')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'pending' => 'Pending',
                        'approved' => 'Approved',
                        'cancelled' => 'Cancelled',
                        'rejected' => 'Rejected',
                    ]),
                SelectFilter::make('visibility')
                    ->options([
                        'public' => 'Public',
                        'unlisted' => 'Unlisted',
                        'private' => 'Private',
                    ]),
                SelectFilter::make('event_structure')
                    ->label('Structure')
                    ->options([
                        EventStructure::Standalone->value => EventStructure::Standalone->label(),
                        EventStructure::ParentProgram->value => EventStructure::ParentProgram->label(),
                        EventStructure::ChildEvent->value => EventStructure::ChildEvent->label(),
                    ]),
                SelectFilter::make('institution')
                    ->relationship('institution', 'name'),
                TernaryFilter::make('is_active')
                    ->label('Active'),
                TernaryFilter::make('has_active_notice')
                    ->label('Has Active Notice')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereHas('publishedChangeAnnouncements'),
                        false: fn (Builder $query): Builder => $query->whereDoesntHave('publishedChangeAnnouncements'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                SelectFilter::make('event_type')
                    ->label('Event Type')
                    ->options(EventType::class)
                    ->query(
                        fn (Builder $query, array $data) => $query
                            ->when(
                                $data['value'],
                                fn ($q, $value) => $q->whereJsonContains('event_type', $value)
                            )
                    ),
                SelectFilter::make('timing_mode')
                    ->label('Timing Mode')
                    ->options([
                        TimingMode::Absolute->value => TimingMode::Absolute->label(),
                        TimingMode::PrayerRelative->value => TimingMode::PrayerRelative->label(),
                    ]),
                SelectFilter::make('prayer_reference')
                    ->label('Prayer Reference')
                    ->options(
                        collect(PrayerReference::cases())
                            ->mapWithKeys(fn (PrayerReference $reference): array => [$reference->value => $reference->label()])
                            ->all()
                    ),
                SelectFilter::make('prayer_time')
                    ->label('Prayer Time')
                    ->options(
                        collect(EventPrayerTime::cases())
                            ->mapWithKeys(fn (EventPrayerTime $prayerTime): array => [$prayerTime->value => $prayerTime->getLabel()])
                            ->all()
                    )
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if (! is_string($value) || $value === '') {
                            return $query;
                        }

                        $prayerTime = EventPrayerTime::tryFrom($value);

                        if (! $prayerTime instanceof EventPrayerTime) {
                            return $query;
                        }

                        if ($prayerTime->isCustomTime()) {
                            return $query->where('timing_mode', TimingMode::Absolute->value);
                        }

                        $query->where('timing_mode', TimingMode::PrayerRelative->value)
                            ->where(function (Builder $timingQuery) use ($prayerTime): void {
                                $timingQuery->where('prayer_display_text', 'like', '%'.$prayerTime->getLabel().'%');

                                if (($reference = $prayerTime->toPrayerReference()) instanceof PrayerReference) {
                                    $timingQuery->orWhere('prayer_reference', $reference->value);
                                }
                            });

                        if (($reference = $prayerTime->toPrayerReference()) instanceof PrayerReference) {
                            $query->where('prayer_reference', $reference->value);
                        }

                        return $query;
                    }),
                Filter::make('starts_at_range')
                    ->label('Start Date Range')
                    ->form([
                        DatePicker::make('starts_after')
                            ->label('Starts After'),
                        DatePicker::make('starts_before')
                            ->label('Starts Before'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            filled($data['starts_after'] ?? null),
                            fn (Builder $builder): Builder => $builder->whereDate('starts_at', '>=', (string) $data['starts_after'])
                        )
                        ->when(
                            filled($data['starts_before'] ?? null),
                            fn (Builder $builder): Builder => $builder->whereDate('starts_at', '<=', (string) $data['starts_before'])
                        ))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if (filled($data['starts_after'] ?? null)) {
                            $indicators[] = 'Starts after '.Str::of((string) $data['starts_after'])->toString();
                        }

                        if (filled($data['starts_before'] ?? null)) {
                            $indicators[] = 'Starts before '.Str::of((string) $data['starts_before'])->toString();
                        }

                        return $indicators;
                    }),
            ])
            ->recordActions([
                self::generateCoverImageTableAction(),
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::generateCoverImageBulkAction(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    private static function generateCoverImageTableAction(): Action
    {
        return Action::make('generate_cover_image')
            ->label('Generate Cover')
            ->icon('heroicon-o-sparkles')
            ->color('info')
            ->schema(self::coverGenerationForm())
            ->requiresConfirmation()
            ->modalHeading('Generate Event Cover')
            ->modalDescription('This makes one OpenAI image generation request and replaces the event cover media.')
            ->modalSubmitActionLabel('Generate cover')
            ->action(function (Event $record, array $data): void {
                try {
                    self::generateCoverForRecord($record, $data);

                    Notification::make()
                        ->title('Event cover generated')
                        ->success()
                        ->send();
                } catch (Throwable $exception) {
                    report($exception);

                    Notification::make()
                        ->title('Event cover generation failed')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                }
            })
            ->visible(fn (Event $record): bool => self::canGenerateCover($record));
    }

    private static function generateCoverImageBulkAction(): BulkAction
    {
        return BulkAction::make('generate_cover_images')
            ->label('Generate Covers')
            ->icon('heroicon-o-sparkles')
            ->color('info')
            ->schema(self::coverGenerationForm())
            ->requiresConfirmation()
            ->modalHeading('Generate Event Covers')
            ->modalDescription('This makes one OpenAI image generation request per selected event and replaces each event cover media.')
            ->modalSubmitActionLabel('Generate covers')
            ->action(function (\Illuminate\Database\Eloquent\Collection $records, array $data): void {
                $generated = 0;
                $skipped = 0;
                $failed = 0;

                foreach ($records as $record) {
                    if (! $record instanceof Event || ! self::canGenerateCover($record)) {
                        $skipped++;

                        continue;
                    }

                    try {
                        self::generateCoverForRecord($record, $data);
                        $generated++;
                    } catch (Throwable $exception) {
                        report($exception);
                        $failed++;
                    }
                }

                $notification = Notification::make()
                    ->title("Generated {$generated} event cover".($generated === 1 ? '' : 's'));

                if ($failed > 0 || $skipped > 0) {
                    $notification
                        ->body("Failed: {$failed}. Skipped: {$skipped}.")
                        ->warning();
                } else {
                    $notification->success();
                }

                $notification->send();
            })
            ->visible(fn (): bool => self::canSeeBulkCoverGeneration())
            ->deselectRecordsAfterCompletion();
    }

    /**
     * @return array<int, Textarea|Toggle>
     */
    private static function coverGenerationForm(): array
    {
        return [
            Textarea::make('creative_direction')
                ->label('Creative direction')
                ->rows(3)
                ->maxLength(2000)
                ->helperText('Optional visual direction to merge with the event data and reference media.'),

            Toggle::make('include_existing_media')
                ->label('Use existing media as references')
                ->default(true),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function generateCoverForRecord(Event $record, array $data): void
    {
        $user = auth()->user();

        app(GenerateEventCoverImageAction::class)->handle(
            event: $record,
            creativeDirection: filled($data['creative_direction'] ?? null) ? (string) $data['creative_direction'] : null,
            includeExistingMedia: (bool) ($data['include_existing_media'] ?? true),
            user: $user instanceof User ? $user : null,
            request: request(),
        );
    }

    private static function canGenerateCover(Event $record): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('update', $record);
    }

    private static function canSeeBulkCoverGeneration(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasApplicationAdminAccess();
    }

    private static function canManageFeaturedFlag(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->hasApplicationAdminAccess()
            && Filament::getCurrentPanel()?->getId() === 'admin';
    }

    protected static function formatEnumCollection(mixed $state, string $enumClass): string
    {
        $items = $state instanceof Collection
            ? $state->all()
            : (is_array($state) ? $state : [$state]);

        return collect($items)
            ->filter(fn (mixed $value): bool => filled($value))
            ->map(fn (mixed $value): string => self::formatEnumValue($value, $enumClass))
            ->filter()
            ->implode(', ');
    }

    protected static function formatEnumValue(mixed $state, ?string $enumClass = null): string
    {
        if ($state instanceof BackedEnum) {
            return self::resolveEnumLabel($state);
        }

        if (is_string($state) && $enumClass !== null && is_subclass_of($enumClass, BackedEnum::class)) {
            $enum = $enumClass::tryFrom($state);

            if ($enum instanceof BackedEnum) {
                return self::resolveEnumLabel($enum);
            }
        }

        return is_scalar($state) ? (string) $state : '';
    }

    protected static function resolveEnumLabel(BackedEnum $enum): string
    {
        if (method_exists($enum, 'getLabel')) {
            return (string) $enum->getLabel();
        }

        if (method_exists($enum, 'label')) {
            return (string) $enum->label();
        }

        return (string) $enum->value;
    }
}
