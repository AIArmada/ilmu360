<?php

namespace App\Filament\Pages;

use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\DeletedModels\Models\DeletedModel;
use Throwable;
use UnitEnum;

class DeletedUsers extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-trash';

    protected static ?string $navigationLabel = 'Deleted Users';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 13;

    protected string $view = 'filament.pages.deleted-users';

    #[\Override]
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasRole('super_admin');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => DeletedModel::query()
                ->where('model', $this->userMorphClass())
                ->latest())
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->state(fn (DeletedModel $record): string => (string) data_get($record->values, 'name', '-')),
                TextColumn::make('email')
                    ->label('Email')
                    ->state(fn (DeletedModel $record): string => (string) data_get($record->values, 'email', '-')),
                TextColumn::make('phone')
                    ->label('Phone')
                    ->state(fn (DeletedModel $record): string => (string) data_get($record->values, 'phone', '-'))
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Deleted')
                    ->since()
                    ->sortable(),
            ])
            ->actions([
                Action::make('restore')
                    ->label('Restore')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Restore Deleted User')
                    ->modalDescription('This will restore the user record and rebuild the relationships that were captured at deletion time.')
                    ->action(function (DeletedModel $record): void {
                        try {
                            $restoredUser = User::restoreDeletedUser($record->key);

                            $this->flushCachedTableRecords();
                            $this->resetPage();

                            Notification::make()
                                ->title('User restored')
                                ->body(sprintf('%s has been restored successfully.', $restoredUser->name))
                                ->success()
                                ->send();
                        } catch (Throwable $throwable) {
                            Notification::make()
                                ->title('Restore failed')
                                ->body($throwable->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    protected function userMorphClass(): string
    {
        return (new User)->getMorphClass();
    }
}
