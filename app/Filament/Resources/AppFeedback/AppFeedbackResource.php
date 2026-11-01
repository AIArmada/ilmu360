<?php

declare(strict_types=1);

namespace App\Filament\Resources\AppFeedback;

use App\Filament\Resources\AppFeedback\Pages\ListAppFeedback;
use App\Filament\Resources\AppFeedback\Pages\ViewAppFeedback;
use App\Filament\Resources\AppFeedback\Schemas\AppFeedbackInfolist;
use App\Filament\Resources\AppFeedback\Tables\AppFeedbackTable;
use App\Models\AppFeedback;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class AppFeedbackResource extends Resource
{
    protected static ?string $model = AppFeedback::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $recordTitleAttribute = 'title';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Maklum balas aplikasi';

    protected static ?string $modelLabel = 'Maklum balas';

    protected static ?string $pluralModelLabel = 'Maklum balas aplikasi';

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
