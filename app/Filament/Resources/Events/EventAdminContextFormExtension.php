<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events;

use AIArmada\Events\Enums\ScheduleKind;
use AIArmada\FilamentEvents\Contracts\EventFormExtension;
use App\Enums\EventAgeGroup;
use App\Enums\EventGenderRestriction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;

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
                    Select::make('institution_id')
                        ->label('Institution')
                        ->relationship('institution', 'name')
                        ->searchable()
                        ->preload()
                        ->nullable()
                        ->helperText('Use this for an institution-hosted event without a venue record.'),
                    Select::make('default_venue_id')
                        ->label('Venue')
                        ->relationship('venue', 'name')
                        ->searchable()
                        ->preload()
                        ->nullable()
                        ->helperText('Use the venue when the event takes place at a named location.'),
                    Select::make('persons')
                        ->label('Speakers')
                        ->relationship('persons', 'name')
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->helperText('Speaker identities are stored as event involvements.'),
                    Select::make('references')
                        ->label('References')
                        ->relationship('references', 'title')
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->helperText('Attach the source works that support this event.'),
                ])
                ->columns(2),
            Section::make('Schedule & Delivery')
                ->schema([
                    Select::make('schedule_kind')
                        ->label('Schedule type')
                        ->options(ScheduleKind::options())
                        ->default(ScheduleKind::Single->value)
                        ->required(),
                    TextInput::make('timezone')
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
                        ->required(),
                    Select::make('age_group')
                        ->label('Age groups')
                        ->options(EventAgeGroup::class)
                        ->multiple()
                        ->required(),
                    Toggle::make('children_allowed')
                        ->label('Children welcome'),
                    Toggle::make('is_muslim_only')
                        ->label('Muslim-only audience'),
                    Toggle::make('is_featured')
                        ->label('Feature this event'),
                ])
                ->columns(2),
        ];
    }
}
