<?php

namespace App\Filament\Resources\MembershipApplications;

use App\Filament\RelationManagers\AuditsRelationManager;
use App\Filament\Resources\MembershipApplications\Pages\ListMembershipApplications;
use App\Filament\Resources\MembershipApplications\Pages\ViewMembershipApplication;
use App\Filament\Resources\MembershipApplications\Schemas\MembershipApplicationInfolist;
use App\Filament\Resources\MembershipApplications\Tables\MembershipApplicationsTable;
use App\Models\MembershipApplication;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class MembershipApplicationResource extends Resource
{
    private static ?string $navigationBadgeScope = null;

    private static ?int $pendingNavigationCount = null;

    protected static ?string $model = MembershipApplication::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $recordTitleAttribute = 'id';

    protected static string|UnitEnum|null $navigationGroup = 'Moderation';

    protected static ?int $navigationSort = 4;

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return MembershipApplicationInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return MembershipApplicationsTable::configure($table);
    }

    /**
     * @return Builder<MembershipApplication>
     */
    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<MembershipApplication> $query */
        $query = parent::getEloquentQuery();

        return $query->with(['applicant', 'reviewer']);
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
            'index' => ListMembershipApplications::route('/'),
            'view' => ViewMembershipApplication::route('/{record}'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $scope = app()->bound('request') ? spl_object_hash(request()) : 'console';

        if (self::$navigationBadgeScope !== $scope) {
            self::$navigationBadgeScope = $scope;
            self::$pendingNavigationCount = MembershipApplication::query()
                ->where('status', 'pending')
                ->count();
        }

        $count = self::$pendingNavigationCount ?? 0;

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
