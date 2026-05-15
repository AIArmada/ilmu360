<?php

namespace App\Filament\Ahli\Resources\Institutions;

use App\Filament\Ahli\Resources\Institutions\Pages\EditInstitution;
use App\Filament\RelationManagers\AuditsRelationManager;
use App\Filament\Resources\Institutions\RelationManagers\DonationChannelsRelationManager;
use App\Filament\Resources\Institutions\RelationManagers\MemberInvitationsRelationManager;
use App\Filament\Resources\Institutions\Schemas\InstitutionForm;
use App\Models\Institution;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class InstitutionResource extends Resource
{
    protected static ?string $model = Institution::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Institutions';

    protected static ?string $navigationParentItem = 'Events';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'institutions';

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return InstitutionForm::configure($schema);
    }

    /**
     * @return Builder<Institution>
     */
    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Institution> $query */
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn(
            'institutions.id',
            $user->institutions()->select('institutions.id')
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
            'donation_channels' => DonationChannelsRelationManager::class,
            'member_invitations' => MemberInvitationsRelationManager::class,
            AuditsRelationManager::class,
        ];
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'edit' => EditInstitution::route('/{record}/edit'),
        ];
    }

    /**
     * @param  array<mixed>  $parameters
     */
    #[\Override]
    public static function getIndexUrl(
        array $parameters = [],
        bool $isAbsolute = true,
        ?string $panel = null,
        ?Model $tenant = null,
        bool $shouldGuessMissingParameters = false
    ): string {
        return Filament::getPanel($panel ?? 'ahli')->getUrl($tenant) ?? route('dashboard.institutions');
    }
}
