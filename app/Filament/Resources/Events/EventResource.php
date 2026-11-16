<?php

declare(strict_types=1);

namespace App\Filament\Resources\Events;

use AIArmada\CommerceSupport\Support\Filament\OwnerUiScope;
use AIArmada\Events\Contracts\EventCloneService;
use AIArmada\Events\Contracts\EventLifecycleWorkflow;
use AIArmada\Events\Enums\RegistrationMode;
use AIArmada\FilamentEvents\Actions\Exporter\EventExporter;
use AIArmada\FilamentEvents\Contracts\EventFormExtension;
use AIArmada\FilamentEvents\Resources\EventResource\RelationManagers\AttendancesRelationManager;
use AIArmada\FilamentEvents\Resources\EventResource\RelationManagers\ClassificationsRelationManager;
use AIArmada\FilamentEvents\Resources\EventResource\RelationManagers\InvolvementsRelationManager;
use AIArmada\FilamentEvents\Resources\EventResource\RelationManagers\LocationsRelationManager;
use AIArmada\FilamentEvents\Resources\EventResource\RelationManagers\OccurrencesRelationManager;
use AIArmada\FilamentEvents\Resources\EventResource\RelationManagers\RegistrationsRelationManager;
use AIArmada\FilamentEvents\Resources\EventResource\RelationManagers\SessionsRelationManager;
use AIArmada\Ticketing\Enums\PricingMode;
use App\Features\Events\Api\EventAuthoring;
use App\Filament\Resources\Events\Pages\CreateEvent;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\Pages\ListEvents;
use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Models\Event;
use App\Shared\Events\Enums\EventFormat;
use App\Shared\Events\Enums\EventVisibility;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ExportAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\CodeEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Contracts\HasColor;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

final class EventResource extends Resource
{
    #[\Override]
    protected static ?string $model = Event::class;

    #[\Override]
    protected static ?string $slug = 'events';

    #[\Override]
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        $group = config('filament-events.navigation.group');

        return is_string($group) || $group instanceof UnitEnum ? $group : null;
    }

    public static function getNavigationSort(): ?int
    {
        $sort = config('filament-events.resources.navigation_sort.event');

        return is_numeric($sort) ? (int) $sort : null;
    }

    /**
     * @return Builder<Event>
     */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Event> $query */
        $query = parent::getEloquentQuery();

        return OwnerUiScope::apply($query, includeGlobal: false)
            ->withCount('occurrences');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('Basic Details')
                ->schema([
                    TextInput::make('title')
                        ->required()
                        ->maxLength(255),
                    Textarea::make('description')
                        ->rows(5),
                ])
                ->columns(2),
            ...self::configuredFormExtensions(),
            Section::make('Lifecycle')
                ->schema([
                    Select::make('status')
                        ->options(fn (): array => self::eventStatusOptions())
                        ->default('draft')
                        ->hiddenOn('edit')
                        ->required(),
                    Select::make('visibility')
                        ->options(collect(EventVisibility::cases())->mapWithKeys(fn (EventVisibility $visibility): array => [
                            $visibility->value => $visibility->getLabel(),
                        ])->all())
                        ->default(EventVisibility::Public->value)
                        ->hiddenOn('edit')
                        ->required(),
                    Select::make('event_format')
                        ->options(collect(EventFormat::cases())->mapWithKeys(fn (EventFormat $format): array => [
                            $format->value => $format->getLabel(),
                        ])->all())
                        ->default(EventFormat::Physical->value)
                        ->required(),
                ])
                ->columns(3),
            Section::make('Pricing & Registration')
                ->schema([
                    Select::make('pricing_mode')
                        ->options(PricingMode::options())
                        ->placeholder('Derive from ticket types'),
                    Select::make('registration_mode')
                        ->options(RegistrationMode::options())
                        ->default(fn (): mixed => app(EventAuthoring::class)->adminFormDefaults()['registration_mode'] ?? null),
                    Select::make('issue_passes_for_free')
                        ->label('Issue passes for free registrations')
                        ->nullable()
                        ->options([
                            1 => 'Issue passes',
                            0 => 'Do not issue passes',
                        ])
                        ->placeholder('Inherit from parent/config')
                        ->helperText('Leave blank to use the configured or inherited default.'),
                ])
                ->columns(2),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Identity')
                    ->schema([
                        TextEntry::make('title'),
                        TextEntry::make('slug'),
                        TextEntry::make('summary'),
                        TextEntry::make('description'),
                        TextEntry::make('type'),
                    ])
                    ->columns(2),
                Section::make('Lifecycle')
                    ->schema([
                        TextEntry::make('status')->badge(),
                        TextEntry::make('visibility')
                            ->badge()
                            ->formatStateUsing(fn (mixed $state): string => self::extractString($state)),
                        TextEntry::make('event_format')
                            ->badge()
                            ->state(fn (Event $record): ?string => $record->delivery_mode)
                            ->formatStateUsing(fn (mixed $state): string => self::eventFormatLabel($state)),
                        TextEntry::make('published_at')->dateTime(),
                        TextEntry::make('cancelled_at')->dateTime(),
                        TextEntry::make('postponed_at')->dateTime(),
                        TextEntry::make('archived_at')->dateTime(),
                        TextEntry::make('completed_at')->dateTime(),
                        TextEntry::make('last_state_change_at')->dateTime()->label('Last Status Change'),
                    ])
                    ->columns(2),
                Section::make('Ownership')
                    ->schema([
                        TextEntry::make('owner_type'),
                        TextEntry::make('owner_id'),
                    ])
                    ->columns(2),
                Section::make('Metadata')
                    ->schema([
                        CodeEntry::make('metadata')
                            ->visible(fn (?array $state): bool => ! empty($state)),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (mixed $state): string|array|null => $state instanceof HasColor ? $state->getColor() : 'gray'),
                Tables\Columns\TextColumn::make('visibility')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => self::extractString($state))
                    ->color(fn (mixed $state): string => match (self::extractString($state)) {
                        'public' => 'success',
                        'unlisted' => 'warning',
                        'private' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('event_format')
                    ->badge()
                    ->state(fn (Event $record): ?string => $record->delivery_mode)
                    ->formatStateUsing(fn (mixed $state): string => self::eventFormatLabel($state))
                    ->color(fn (mixed $state): string => match (self::extractString($state)) {
                        'physical' => 'info',
                        'online' => 'success',
                        'hybrid' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('occurrences_count')
                    ->label('Occurrences')
                    ->counts('occurrences'),
                Tables\Columns\TextColumn::make('published_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('last_state_change_at')
                    ->dateTime()
                    ->label('Last Status Change')
                    ->sortable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(fn (): array => self::eventStatusOptions()),
                Tables\Filters\SelectFilter::make('visibility')
                    ->options(self::eventVisibilityOptions()),
                Tables\Filters\SelectFilter::make('event_format')
                    ->attribute('delivery_mode')
                    ->options(self::eventFormatOptions()),
            ])
            ->headerActions([
                ExportAction::make()
                    ->exporter(EventExporter::class)
                    ->label('Export Events'),
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('publish')
                    ->label('Publish')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->action(function (Event $record): void {
                        app(EventLifecycleWorkflow::class)->publish($record);
                    })
                    ->visible(fn (?Event $record): bool => $record instanceof \App\Models\Event && in_array((string) $record->status, ['draft', 'pending', 'pending_review', 'needs_changes'], true))
                    ->requiresConfirmation(),
                Action::make('archive')
                    ->label('Archive')
                    ->icon('heroicon-o-archive-box')
                    ->color('warning')
                    ->action(function (Event $record): void {
                        app(EventLifecycleWorkflow::class)->archive($record);
                    })
                    ->visible(fn (?Event $record): bool => $record instanceof \App\Models\Event && (string) $record->status === 'approved')
                    ->requiresConfirmation(),
                Action::make('cancel')
                    ->label('Cancel')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->schema([
                        Textarea::make('reason')->required(),
                    ])
                    ->action(function (array $data, Event $record): void {
                        app(EventLifecycleWorkflow::class)->cancel($record, $data['reason']);
                    })
                    ->visible(fn (?Event $record): bool => $record instanceof \App\Models\Event && ! in_array((string) $record->status, ['cancelled', 'completed', 'archived'], true))
                    ->requiresConfirmation(),
                Action::make('clone')
                    ->label('Clone')
                    ->icon('heroicon-o-document-duplicate')
                    ->color('gray')
                    ->action(function (Event $record): void {
                        $clone = app(EventCloneService::class)->cloneEvent($record, [
                            'clone_occurrences' => false,
                        ]);

                        redirect(EventResource::getUrl('edit', ['record' => $clone]));
                    })
                    ->requiresConfirmation(),
            ]);
    }

    public static function getRelations(): array
    {
        $relations = [
            OccurrencesRelationManager::class,
            SessionsRelationManager::class,
            LocationsRelationManager::class,
            InvolvementsRelationManager::class,
            RegistrationsRelationManager::class,
            AttendancesRelationManager::class,
            ClassificationsRelationManager::class,
        ];

        $configuredRelations = config('filament-events.resources.event_relation_managers', []);

        if (! is_array($configuredRelations)) {
            return $relations;
        }

        foreach ($configuredRelations as $relationClass) {
            if (is_string($relationClass) && is_a($relationClass, RelationManager::class, true)) {
                $relations[] = $relationClass;
            }
        }

        return array_values(array_unique($relations));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEvents::route('/'),
            'create' => CreateEvent::route('/create'),
            'view' => ViewEvent::route('/{record}'),
            'edit' => EditEvent::route('/{record}/edit'),
        ];
    }

    /**
     * @return array<int, Component>
     */
    private static function configuredFormExtensions(): array
    {
        $configuredExtensions = config('filament-events.resources.event_form_extensions', []);

        if (! is_array($configuredExtensions)) {
            return [];
        }

        $components = [];

        foreach ($configuredExtensions as $extensionClass) {
            if (! is_string($extensionClass) || ! is_a($extensionClass, EventFormExtension::class, true)) {
                continue;
            }

            /** @var EventFormExtension $extension */
            $extension = app($extensionClass);
            array_push($components, ...$extension->components());
        }

        return $components;
    }

    /**
     * @return array<string, string>
     */
    private static function eventStatusOptions(): array
    {
        $modelClass = self::getModel();
        $statusCast = app($modelClass)->getCasts()['status'] ?? null;

        if ($statusCast === null) {
            return [];
        }

        if (method_exists($statusCast, 'getStatesLabel')) {
            return $statusCast::getStatesLabel($modelClass);
        }

        if (method_exists($statusCast, 'options')) {
            return $statusCast::options();
        }

        return [];
    }

    /**
     * @return array<string, string>
     */
    private static function eventFormatOptions(): array
    {
        return collect(EventFormat::cases())
            ->mapWithKeys(fn (EventFormat $format): array => [$format->value => $format->getLabel()])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private static function eventVisibilityOptions(): array
    {
        return collect(EventVisibility::cases())
            ->mapWithKeys(fn (EventVisibility $visibility): array => [$visibility->value => $visibility->getLabel()])
            ->all();
    }

    private static function eventFormatLabel(mixed $value): string
    {
        $format = EventFormat::tryFrom(self::extractString($value));

        return $format?->getLabel() ?? self::extractString($value);
    }

    private static function extractString(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return is_string($value) ? $value : '';
    }
}
