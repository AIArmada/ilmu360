<?php

namespace App\Filament\Resources\MembershipClaims;

use App\Filament\RelationManagers\AuditsRelationManager;
use App\Filament\Resources\MembershipClaims\Pages\ListMembershipClaims;
use App\Filament\Resources\MembershipClaims\Pages\ViewMembershipClaim;
use App\Filament\Resources\MembershipClaims\Schemas\MembershipClaimInfolist;
use App\Filament\Resources\MembershipClaims\Tables\MembershipClaimsTable;
use App\Models\MembershipClaim;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class MembershipClaimResource extends Resource
{
    protected static ?string $model = MembershipClaim::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $recordTitleAttribute = 'id';

    protected static string|UnitEnum|null $navigationGroup = 'Moderation';

    protected static ?int $navigationSort = 4;

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return MembershipClaimInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return MembershipClaimsTable::configure($table);
    }

    /**
     * @return Builder<MembershipClaim>
     */
    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<MembershipClaim> $query */
        $query = parent::getEloquentQuery();

        return $query->with(['claimant', 'reviewer']);
    }

    #[\Override]
    public static function getRelations(): array
    {
        return [
            AuditsRelationManager::class,
        ];
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListMembershipClaims::route('/'),
            'view' => ViewMembershipClaim::route('/{record}'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $count = MembershipClaim::query()
            ->where('status', 'pending')
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return self::getNavigationBadge() !== null ? 'warning' : null;
    }

    #[\Override]
    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'admin', 'moderator']) ?? false;
    }
}
