<?php

namespace App\Filament\Ahli\Resources\Speakers;

use App\Filament\Ahli\Resources\Speakers\Pages\EditSpeaker;
use App\Filament\Ahli\Resources\Speakers\Pages\ListSpeakers;
use App\Filament\Ahli\Resources\Speakers\Pages\ViewSpeaker;
use App\Filament\RelationManagers\AuditsRelationManager;
use App\Filament\Resources\Speakers\RelationManagers\MemberInvitationsRelationManager;
use App\Filament\Resources\Speakers\SpeakerResource as AdminSpeakerResource;
use App\Models\Speaker;
use App\Models\User;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class SpeakerResource extends AdminSpeakerResource
{
    protected static string|UnitEnum|null $navigationGroup = 'Directory';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'speakers';

    #[\Override]
    public static function table(Table $table): Table
    {
        return parent::table($table)
            ->toolbarActions([]);
    }

    /**
     * @return Builder<Speaker>
     */
    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Speaker> $query */
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn(
            'speakers.id',
            $user->speakers()->select('speakers.id')
        );
    }

    #[\Override]
    public static function canCreate(): bool
    {
        return false;
    }

    #[\Override]
    public static function getRelations(): array
    {
        return [
            MemberInvitationsRelationManager::class,
            AuditsRelationManager::class,
        ];
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListSpeakers::route('/'),
            'view' => ViewSpeaker::route('/{record}'),
            'edit' => EditSpeaker::route('/{record}/edit'),
        ];
    }
}
