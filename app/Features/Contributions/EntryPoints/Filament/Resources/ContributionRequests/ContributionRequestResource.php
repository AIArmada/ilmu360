<?php

namespace App\Features\Contributions\EntryPoints\Filament\Resources\ContributionRequests;

use AIArmada\CommerceSupport\Support\VersionedCache;
use App\Features\Contributions\EntryPoints\Filament\Resources\ContributionRequests\Pages\ListContributionRequests;
use App\Features\Contributions\EntryPoints\Filament\Resources\ContributionRequests\Pages\ViewContributionRequest;
use App\Features\Contributions\EntryPoints\Filament\Resources\ContributionRequests\Schemas\ContributionRequestInfolist;
use App\Features\Contributions\EntryPoints\Filament\Resources\ContributionRequests\Support\ContributionRequestPresenter;
use App\Features\Contributions\EntryPoints\Filament\Resources\ContributionRequests\Tables\ContributionRequestsTable;
use App\Features\Contributions\Models\ContributionRequest;
use App\Platform\Auditing\AuditsRelationManager;
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

    #[\Override]
    protected static ?string $model = ContributionRequest::class;

    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    #[\Override]
    protected static ?string $recordTitleAttribute = null;

    #[\Override]
    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        if (! $record instanceof ContributionRequest) {
            return parent::getRecordTitle($record);
        }

        return ContributionRequestPresenter::breadcrumbTitle($record);
    }

    #[\Override]
    protected static string|UnitEnum|null $navigationGroup = 'Moderation';

    #[\Override]
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

        return $query->with(['entity', 'proposer', 'reviewer', 'media']);
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
            self::$pendingNavigationCount = app(VersionedCache::class)->rememberInt(
                ContributionRequest::PENDING_COUNT_VERSION_KEY,
                'nav_badge:contribution_requests:pending:count',
                30,
                static fn (): int => ContributionRequest::query()
                    ->where('status', 'pending')
                    ->count(),
            );
        }

        $count = self::$pendingNavigationCount ?? 0;

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return self::getNavigationBadge() !== null ? 'warning' : null;
    }

    public static function flushNavigationBadgeCache(): void
    {
        self::$navigationBadgeScope = null;
        self::$pendingNavigationCount = null;
    }

    #[\Override]
    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'admin', 'moderator']) ?? false;
    }
}
