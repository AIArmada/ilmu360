<?php

namespace App\Features\Reports\EntryPoints\Filament\Resources\Reports;

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
            self::$openNavigationCount = static::getModel()::query()->where('status', 'open')->count();
        }

        $count = self::$openNavigationCount ?? 0;

        return $count > 0 ? (string) $count : null;
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
