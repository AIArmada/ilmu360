<?php

namespace App\Filament\Resources\ContributionRequests;

use App\Filament\RelationManagers\AuditsRelationManager;
use App\Filament\Resources\ContributionRequests\Pages\ListContributionRequests;
use App\Filament\Resources\ContributionRequests\Pages\ViewContributionRequest;
use App\Filament\Resources\ContributionRequests\Schemas\ContributionRequestInfolist;
use App\Filament\Resources\ContributionRequests\Support\ContributionRequestPresenter;
use App\Filament\Resources\ContributionRequests\Tables\ContributionRequestsTable;
use App\Models\ContributionRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ContributionRequestResource extends Resource
{
    private static ?string $navigationBadgeScope = null;

    private static ?int $pendingNavigationCount = null;

    protected static ?string $model = ContributionRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = null;

    #[\Override]
    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        if (! $record instanceof ContributionRequest) {
            return parent::getRecordTitle($record);
        }

        return ContributionRequestPresenter::breadcrumbTitle($record);
    }

    protected static string|UnitEnum|null $navigationGroup = 'Moderation';

    protected static ?int $navigationSort = 2;

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return ContributionRequestInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return ContributionRequestsTable::configure($table);
    }

    /**
     * @return Builder<ContributionRequest>
     */
    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<ContributionRequest> $query */
        $query = parent::getEloquentQuery();

        return $query->with(['entity', 'proposer', 'reviewer']);
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
            'index' => ListContributionRequests::route('/'),
            'view' => ViewContributionRequest::route('/{record}'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $scope = app()->bound('request') ? spl_object_hash(request()) : 'console';

        if (self::$navigationBadgeScope !== $scope) {
            self::$navigationBadgeScope = $scope;
            self::$pendingNavigationCount = ContributionRequest::query()
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
