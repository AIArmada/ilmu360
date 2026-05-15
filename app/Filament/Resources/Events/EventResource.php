<?php

namespace App\Filament\Resources\Events;

use App\Filament\RelationManagers\AuditsRelationManager;
use App\Filament\Resources\Events\Pages\CreateEvent;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\Pages\ListEvents;
use App\Filament\Resources\Events\Pages\ViewEvent;
use App\Filament\Resources\Events\RelationManagers\ChildEventsRelationManager;
use App\Filament\Resources\Events\RelationManagers\EventUsersRelationManager;
use App\Filament\Resources\Events\RelationManagers\MediaLinksRelationManager;
use App\Filament\Resources\Events\RelationManagers\MemberInvitationsRelationManager;
use App\Filament\Resources\Events\RelationManagers\ModerationReviewsRelationManager;
use App\Filament\Resources\Events\RelationManagers\RegistrationsRelationManager;
use App\Filament\Resources\Events\Schemas\EventForm;
use App\Filament\Resources\Events\Schemas\EventInfolist;
use App\Filament\Resources\Events\Tables\EventsTable;
use App\Models\Event;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class EventResource extends Resource
{
    protected static ?string $model = Event::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $recordTitleAttribute = 'title';

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return EventForm::configure($schema);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return EventInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return EventsTable::configure($table);
    }

    /**
     * @return Builder<Event>
     */
    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Event> $query */
        $query = parent::getEloquentQuery();

        return $query
            ->with([
                'media',
                'parentEvent',
                'institution',
                'institution.media',
                'settings',
                'submitter',
                'latestPublishedChangeAnnouncement',
            ]);
    }

    #[\Override]
    public static function getRelations(): array
    {
        return [
            ChildEventsRelationManager::class,
            MediaLinksRelationManager::class,
            EventUsersRelationManager::class,
            MemberInvitationsRelationManager::class,
            ModerationReviewsRelationManager::class,
            RegistrationsRelationManager::class,
            AuditsRelationManager::class,
        ];
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListEvents::route('/'),
            'create' => CreateEvent::route('/create'),
            'view' => ViewEvent::route('/{record}'),
            'edit' => EditEvent::route('/{record}/edit'),
        ];
    }
}
