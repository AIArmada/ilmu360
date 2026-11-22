<?php

declare(strict_types=1);

namespace App\Features\Events\EntryPoints\Filament\Resources\Events;

use AIArmada\Events\Enums\ScheduleKind;
use AIArmada\FilamentEvents\Contracts\EventFormExtension;
use App\Features\Events\Api\Enums\EventAgeGroup;
use App\Features\Events\Api\Enums\EventGenderRestriction;
use App\Features\Events\Api\EventAuthoring;
use App\Features\Events\Api\EventCategoryCatalog;
use App\Features\Institutions\Api\InstitutionLookup;
use App\Features\Persons\Api\PersonLookup;
use App\Features\Places\Api\PlaceCatalog;
use App\Features\Prayer\Api\EventPrayerTime;
use App\Features\References\Api\ReferenceLookup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

final class EventAdminContextFormExtension implements EventFormExtension
{
    /**
     * @return array<int, Component>
     */
    public function components(): array
    {
        return [
            Section::make('Event Context')
                ->description('Connect this event to the organisation, venue, and people that make up the programme.')
                ->schema([
                    Select::make('primary_organizer_id')
                        ->label('Primary organizer')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => $this->organizerOptions($search))
                        ->getOptionLabelUsing(fn (mixed $value): ?string => is_string($value) ? $this->organizerLabel($value) : null)
                        ->required(),
                    Select::make('institution_id')
                        ->label('Institution')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => $this->institutionNameOptions($search))
                        ->getOptionLabelUsing(fn (mixed $value): ?string => is_string($value) ? $this->institutionName($value) : null)
                        ->nullable()
                        ->helperText('Use this for an institution-hosted event without a venue record.'),
                    Select::make('venue_id')
                        ->label('Venue')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => $this->venueNameOptions($search))
                        ->getOptionLabelUsing(fn (mixed $value): ?string => is_string($value) ? $this->venueName($value) : null)
                        ->nullable()
                        ->helperText('Use the venue when the event takes place at a named location.'),
                    Select::make('persons')
                        ->label('Speakers')
                        ->multiple()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => $this->personNameOptions($search))
                        ->getOptionLabelsUsing(fn (array $values): array => $this->personNameLabels($values))
                        ->helperText('Speaker identities are stored as event involvements.'),
                    Select::make('references')
                        ->label('References')
                        ->multiple()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => $this->referenceOptions($search))
                        ->getOptionLabelsUsing(fn (array $values): array => $this->referenceLabels($values))
                        ->helperText('Attach the source works that support this event.'),
                    Select::make('event_category_ids')
                        ->label('Categories')
                        ->options(fn (): array => app(EventCategoryCatalog::class)->options())
                        ->default(fn (): array => (array) (app(EventAuthoring::class)->adminFormDefaults()['event_category_ids'] ?? []))
                        ->multiple()
                        ->searchable()
                        ->required(fn (string $operation): bool => $operation === 'create'),
                ])
                ->columns(2),
            Section::make('Schedule & Delivery')
                ->schema([
                    DatePicker::make('event_date')
                        ->label('Event date')
                        ->required(),
                    Select::make('prayer_time')
                        ->label('Prayer time')
                        ->options(collect(EventPrayerTime::cases())->mapWithKeys(fn (EventPrayerTime $time): array => [
                            $time->value => $time->getLabel(),
                        ])->all())
                        ->default(EventPrayerTime::LainWaktu->value)
                        ->live()
                        ->required(),
                    TimePicker::make('custom_time')
                        ->label('Start time')
                        ->seconds(false)
                        ->visible(fn (Get $get): bool => $get('prayer_time') === EventPrayerTime::LainWaktu->value)
                        ->required(fn (Get $get): bool => $get('prayer_time') === EventPrayerTime::LainWaktu->value),
                    DatePicker::make('end_date')
                        ->label('End date')
                        ->nullable(),
                    TimePicker::make('end_time')
                        ->label('End time')
                        ->seconds(false)
                        ->nullable(),
                    Select::make('schedule_kind')
                        ->label('Schedule type')
                        ->options(ScheduleKind::options())
                        ->default(ScheduleKind::Single->value)
                        ->required(),
                    TextInput::make('timezone')
                        ->default(fn (): string => (string) (app(EventAuthoring::class)->adminFormDefaults()['timezone'] ?? ''))
                        ->required()
                        ->maxLength(64)
                        ->helperText('Store the IANA timezone used by the event schedule.'),
                    TextInput::make('event_url')
                        ->label('Event website')
                        ->url()
                        ->maxLength(2048),
                    TextInput::make('live_url')
                        ->label('Live stream')
                        ->url()
                        ->maxLength(2048),
                    TextInput::make('recording_url')
                        ->label('Recording')
                        ->url()
                        ->maxLength(2048),
                ])
                ->columns(2),
            Section::make('Audience & Access')
                ->schema([
                    Select::make('gender')
                        ->label('Audience gender')
                        ->options(EventGenderRestriction::class)
                        ->default(EventGenderRestriction::All)
                        ->required(),
                    Select::make('age_group')
                        ->label('Age groups')
                        ->options(EventAgeGroup::class)
                        ->multiple()
                        ->default([EventAgeGroup::AllAges->value])
                        ->required(),
                    Toggle::make('children_allowed')
                        ->label('Children welcome')
                        ->default(false),
                    Toggle::make('is_muslim_only')
                        ->label('Muslim-only audience')
                        ->default(false),
                ])
                ->columns(2),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function institutionNameOptions(string $search, string $prefix = ''): array
    {
        return $this->withPrefix(app(InstitutionLookup::class)->nameOptions($search), $prefix);
    }

    /**
     * @return array<string, string>
     */
    private function personNameOptions(string $search, string $prefix = ''): array
    {
        return $this->withPrefix(app(PersonLookup::class)->nameOptions($search), $prefix);
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<string, string>
     */
    private function personNameLabels(array $ids): array
    {
        return app(PersonLookup::class)->namesByIds($this->stringIds($ids));
    }

    private function institutionName(string $id): ?string
    {
        return app(InstitutionLookup::class)->namesByIds([$id])[$id] ?? null;
    }

    private function venueName(string $id): ?string
    {
        return app(PlaceCatalog::class)->venueNamesByIds([$id])[$id] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private function venueNameOptions(string $search): array
    {
        return app(PlaceCatalog::class)->venueNameOptions($search);
    }

    /**
     * @return array<string, string>
     */
    private function organizerOptions(string $search): array
    {
        return [
            ...$this->institutionNameOptions($search, 'Institution'),
            ...$this->personNameOptions($search, 'Person'),
        ];
    }

    private function organizerLabel(string $id): ?string
    {
        $institution = $this->institutionName($id);

        if ($institution !== null) {
            return "Institution — {$institution}";
        }

        $person = app(PersonLookup::class)->namesByIds([$id])[$id] ?? null;

        return $person === null ? null : "Person — {$person}";
    }

    /**
     * @return array<string, string>
     */
    private function referenceOptions(string $search): array
    {
        return app(ReferenceLookup::class)->adminOptions($search);
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<string, string>
     */
    private function referenceLabels(array $ids): array
    {
        return app(ReferenceLookup::class)->adminLabels($this->stringIds($ids));
    }

    /**
     * @param  array<string, string>  $options
     * @return array<string, string>
     */
    private function withPrefix(array $options, string $prefix): array
    {
        if ($prefix === '') {
            return $options;
        }

        return array_map(static fn (string $name): string => "{$prefix} — {$name}", $options);
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return list<string>
     */
    private function stringIds(array $ids): array
    {
        return array_values(array_filter($ids, is_string(...)));
    }
}
