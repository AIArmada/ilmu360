<?php

declare(strict_types=1);

namespace App\Features\Feedback\EntryPoints\Filament\Resources\AppFeedback;

use AIArmada\CommerceSupport\Persistence\VersionedCache;
use App\Features\Feedback\EntryPoints\Filament\Resources\AppFeedback\Pages\ListAppFeedback;
use App\Features\Feedback\EntryPoints\Filament\Resources\AppFeedback\Pages\ViewAppFeedback;
use App\Features\Feedback\EntryPoints\Filament\Resources\AppFeedback\Schemas\AppFeedbackInfolist;
use App\Features\Feedback\EntryPoints\Filament\Resources\AppFeedback\Tables\AppFeedbackTable;
use App\Features\Feedback\Enums\AppFeedbackStatus;
use App\Features\Feedback\Models\AppFeedback;
use App\Platform\Admin\ExcludedFromAdminApi;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class AppFeedbackResource extends Resource implements ExcludedFromAdminApi
{
    private static ?string $navigationBadgeScope = null;

    private static ?int $openNavigationCount = null;

    #[\Override]
    protected static ?string $model = AppFeedback::class;

    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    #[\Override]
    protected static ?string $recordTitleAttribute = 'title';

    #[\Override]
    protected static string|UnitEnum|null $navigationGroup = 'System';

    #[\Override]
    protected static ?string $navigationLabel = 'Maklum balas aplikasi';

    #[\Override]
    protected static ?string $modelLabel = 'Maklum balas';

    #[\Override]
    protected static ?string $pluralModelLabel = 'Maklum balas aplikasi';

    #[\Override]
    protected static bool $isGloballySearchable = false;

    public static function infolist(Schema $schema): Schema
    {
        return AppFeedbackInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AppFeedbackTable::configure($table);
    }

    /**
     * @return Builder<AppFeedback>
     */
    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        return AppFeedback::query()->with(['reporter', 'handler', 'media']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAppFeedback::route('/'),
            'view' => ViewAppFeedback::route('/{record}'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $scope = app()->bound('request') ? spl_object_hash(request()) : 'console';

        if (self::$navigationBadgeScope !== $scope) {
            self::$navigationBadgeScope = $scope;
            self::$openNavigationCount = app(VersionedCache::class)->rememberInt(
                AppFeedback::OPEN_COUNT_VERSION_KEY,
                'nav_badge:app_feedback:open:count',
                30,
                static fn (): int => AppFeedback::query()
                    ->whereIn('status', [AppFeedbackStatus::New->value, AppFeedbackStatus::Triaged->value])
                    ->count(),
            );
        }

        $count = self::$openNavigationCount ?? 0;

        return $count > 0 ? (string) $count : null;
    }

    #[\Override]
    public static function getNavigationBadgeColor(): ?string
    {
        return self::getNavigationBadge() !== null ? 'warning' : null;
    }

    public static function flushNavigationBadgeCache(): void
    {
        self::$navigationBadgeScope = null;
        self::$openNavigationCount = null;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
