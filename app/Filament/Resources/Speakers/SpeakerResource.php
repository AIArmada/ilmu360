<?php

declare(strict_types=1);

namespace App\Filament\Resources\Speakers;

use App\Filament\RelationManagers\AuditsRelationManager;
use App\Filament\Resources\Speakers\Pages\CreateSpeaker;
use App\Filament\Resources\Speakers\Pages\EditSpeaker;
use App\Filament\Resources\Speakers\Pages\ListSpeakers;
use App\Filament\Resources\Speakers\Pages\ViewSpeaker;
use App\Filament\Resources\Speakers\RelationManagers\EventsRelationManager;
use App\Filament\Resources\Speakers\RelationManagers\FollowersRelationManager;
use App\Filament\Resources\Speakers\RelationManagers\MemberInvitationsRelationManager;
use App\Filament\Resources\Speakers\RelationManagers\MembersRelationManager;
use App\Filament\Resources\Speakers\Schemas\SpeakerForm;
use App\Filament\Resources\Speakers\Tables\SpeakersTable;
use App\Models\Speaker;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class SpeakerResource extends Resource
{
    protected static ?string $model = Speaker::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMicrophone;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|UnitEnum|null $navigationGroup = 'Directory';

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return SpeakerForm::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return SpeakersTable::configure($table);
    }

    #[\Override]
    public static function getRelations(): array
    {
        return [
            MembersRelationManager::class,
            MemberInvitationsRelationManager::class,
            FollowersRelationManager::class,
            RelationManagers\InstitutionsRelationManager::class,
            EventsRelationManager::class,
            AuditsRelationManager::class,
        ];
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListSpeakers::route('/'),
            'create' => CreateSpeaker::route('/create'),
            'view' => ViewSpeaker::route('/{record}'),
            'edit' => EditSpeaker::route('/{record}/edit'),
        ];
    }
}
