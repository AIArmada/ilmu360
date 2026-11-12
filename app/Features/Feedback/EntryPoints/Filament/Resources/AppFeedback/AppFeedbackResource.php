<?php

declare(strict_types=1);

namespace App\Features\Feedback\EntryPoints\Filament\Resources\AppFeedback;

use App\Features\Feedback\EntryPoints\Filament\Resources\AppFeedback\Pages\ListAppFeedback;
use App\Features\Feedback\EntryPoints\Filament\Resources\AppFeedback\Pages\ViewAppFeedback;
use App\Features\Feedback\EntryPoints\Filament\Resources\AppFeedback\Schemas\AppFeedbackInfolist;
use App\Features\Feedback\EntryPoints\Filament\Resources\AppFeedback\Tables\AppFeedbackTable;
use App\Features\Feedback\Models\AppFeedback;
use App\Platform\Admin\ExcludedFromAdminApi;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class AppFeedbackResource extends Resource implements ExcludedFromAdminApi
{
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

    public static function getPages(): array
    {
        return [
            'index' => ListAppFeedback::route('/'),
            'view' => ViewAppFeedback::route('/{record}'),
        ];
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
