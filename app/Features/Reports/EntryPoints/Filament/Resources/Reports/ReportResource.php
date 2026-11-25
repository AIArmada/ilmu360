<?php

namespace App\Features\Reports\EntryPoints\Filament\Resources\Reports;

use AIArmada\CommerceSupport\Persistence\VersionedCache;
use App\Features\Reports\EntryPoints\Filament\Resources\Reports\Pages\CreateReport;
use App\Features\Reports\EntryPoints\Filament\Resources\Reports\Pages\EditReport;
use App\Features\Reports\EntryPoints\Filament\Resources\Reports\Pages\ListReports;
use App\Features\Reports\EntryPoints\Filament\Resources\Reports\Schemas\ReportForm;
use App\Features\Reports\EntryPoints\Filament\Resources\Reports\Tables\ReportsTable;
use App\Features\Reports\Models\Report;
use App\Platform\Auditing\AuditsRelationManager;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ReportResource extends Resource
{
    private static ?string $navigationBadgeScope = null;

    private static ?int $openNavigationCount = null;

    #[\Override]
    protected static ?string $model = Report::class;

    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    #[\Override]
    protected static ?string $recordTitleAttribute = 'id';

    #[\Override]
    protected static string|UnitEnum|null $navigationGroup = 'Moderation';

    #[\Override]
    public static function getNavigationBadge(): ?string
    {
        $scope = app()->bound('request') ? spl_object_hash(request()) : 'console';

        if (self::$navigationBadgeScope !== $scope) {
            self::$navigationBadgeScope = $scope;
            self::$openNavigationCount = app(VersionedCache::class)->rememberInt(
                Report::OPEN_COUNT_VERSION_KEY,
                'nav_badge:reports:open:count',
                30,
                static fn (): int => static::getModel()::query()->where('status', 'open')->count(),
            );
        }

        $count = self::$openNavigationCount ?? 0;

        return $count > 0 ? (string) $count : null;
    }

    #[\Override]
    public static function getNavigationBadgeColor(): ?string
    {
        return self::getNavigationBadge() !== null ? 'danger' : null;
    }

    public static function flushNavigationBadgeCache(): void
    {
        self::$navigationBadgeScope = null;
        self::$openNavigationCount = null;
    }

    /**
     * @return Builder<Report>
     */
    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return Report::query()->with(['entity', 'reporter', 'handler', 'media']);
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return ReportForm::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return ReportsTable::configure($table);
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
            'index' => ListReports::route('/'),
            'create' => CreateReport::route('/create'),
            'edit' => EditReport::route('/{record}/edit'),
        ];
    }
}
