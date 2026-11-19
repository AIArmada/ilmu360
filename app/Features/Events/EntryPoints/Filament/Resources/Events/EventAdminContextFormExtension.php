<?php

declare(strict_types=1);

namespace App\Features\Events\EntryPoints\Filament\Resources\Events;

use AIArmada\Events\Enums\ScheduleKind;
use AIArmada\FilamentEvents\Contracts\EventFormExtension;
use App\Features\Events\Api\EventAuthoring;
use App\Features\Institutions\Models\Institution;
use App\Features\Persons\Models\Person;
use App\Features\Places\Models\Venue;
use App\Features\References\Models\Reference;
use App\Shared\Events\Enums\EventAgeGroup;
use App\Shared\Events\Enums\EventGenderRestriction;
use App\Shared\Events\EventCategoryCatalog;
use App\Shared\Prayer\EventPrayerTime;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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
                        ->getSearchResultsUsing(fn (string $search): array => $this->nameOptions(Institution::class, $search))
                        ->getOptionLabelUsing(fn (mixed $value): ?string => is_string($value) ? $this->nameLabel(Institution::class, $value) : null)
                        ->nullable()
                        ->helperText('Use this for an institution-hosted event without a venue record.'),
                    Select::make('venue_id')
                        ->label('Venue')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => $this->nameOptions(Venue::class, $search))
                        ->getOptionLabelUsing(fn (mixed $value): ?string => is_string($value) ? $this->nameLabel(Venue::class, $value) : null)
                        ->nullable()
                        ->helperText('Use the venue when the event takes place at a named location.'),
                    Select::make('persons')
                        ->label('Speakers')
                        ->multiple()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => $this->nameOptions(Person::class, $search))
                        ->getOptionLabelsUsing(fn (array $values): array => $this->nameLabels(Person::class, $values))
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
     * @param  class-string<Model>  $modelClass
     * @return array<string, string>
     */
    private function nameOptions(string $modelClass, string $search, string $prefix = ''): array
    {
        /** @var Builder<Model> $query */
        $query = $modelClass::query();

        return $query
            ->when(trim($search) !== '', fn (Builder $query): Builder => $query->where('name', 'like', '%'.trim($search).'%'))
            ->orderBy('name')
            ->limit(50)
            ->pluck('name', 'id')
            ->mapWithKeys(fn (mixed $name, mixed $id): array => [
                (string) $id => $prefix === '' ? (string) $name : "{$prefix} — {$name}",
            ])
            ->all();
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function nameLabel(string $modelClass, string $id): ?string
    {
        /** @var Builder<Model> $query */
        $query = $modelClass::query();
        $name = $query->whereKey($id)->value('name');

        return is_string($name) ? $name : null;
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<int, mixed>  $ids
     * @return array<string, string>
     */
    private function nameLabels(string $modelClass, array $ids): array
    {
        $selectedIds = array_values(array_filter($ids, is_string(...)));

        if ($selectedIds === []) {
            return [];
        }

        /** @var Builder<Model> $query */
        $query = $modelClass::query();

        return $query
            ->whereKey($selectedIds)
            ->pluck('name', 'id')
            ->mapWithKeys(fn (mixed $name, mixed $id): array => [(string) $id => (string) $name])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function organizerOptions(string $search): array
    {
        return [
            ...$this->nameOptions(Institution::class, $search, 'Institution'),
            ...$this->nameOptions(Person::class, $search, 'Person'),
        ];
    }

    private function organizerLabel(string $id): ?string
    {
        $institution = $this->nameLabel(Institution::class, $id);

        if ($institution !== null) {
            return "Institution — {$institution}";
        }

        $person = $this->nameLabel(Person::class, $id);

        return $person === null ? null : "Person — {$person}";
    }

    /**
     * @return array<string, string>
     */
    private function referenceOptions(string $search): array
    {
        $query = Reference::query()->with('parentReference.parentReference');

        if (trim($search) !== '') {
            $query->where('title', 'like', '%'.trim($search).'%');
        }

        return $query
            ->orderBy('title')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Reference $reference): array => [(string) $reference->getKey() => $reference->displayTitle()])
            ->all();
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<string, string>
     */
    private function referenceLabels(array $ids): array
    {
        return Reference::query()
            ->with('parentReference.parentReference')
            ->whereKey(array_values(array_filter($ids, is_string(...))))
            ->get()
            ->mapWithKeys(fn (Reference $reference): array => [(string) $reference->getKey() => $reference->displayTitle()])
            ->all();
    }
}
