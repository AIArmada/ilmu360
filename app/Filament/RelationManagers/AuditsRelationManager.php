<?php

namespace App\Filament\RelationManagers;

use App\Filament\Resources\Audits\Schemas\AuditInfolist;
use App\Models\Audit;
use App\Support\Auditing\AuditValuePresenter;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Tapp\FilamentAuditing\Concerns\HasExtraColumns;
use Tapp\FilamentAuditing\Filament\Resources\Audits\Schemas\AuditFilters;
use Tapp\FilamentAuditing\Filament\Tables\Columns\AuditValuesColumn;

class AuditsRelationManager extends RelationManager
{
    use HasExtraColumns;

    protected static string $relationship = 'audits';

    protected static ?string $recordTitleAttribute = 'id';

    /**
     * @var array<string, string>
     */
    protected $listeners = ['updateAuditsRelationManager' => '$refresh'];

    #[\Override]
    public static function isLazy(): bool
    {
        return (bool) config('filament-auditing.is_lazy');
    }

    #[\Override]
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = Filament::auth()->user();

        return $user !== null && $user->can('audit', $ownerRecord);
    }

    #[\Override]
    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return 'Audit Trail';
    }

    #[\Override]
    public function infolist(Schema $schema): Schema
    {
        return AuditInfolist::configure($schema);
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->recordTitle(static fn (Model $record): string => 'Audit')
            ->modifyQueryUsing(function (Builder $query) {
                $query->with(['user', 'auditable'])
                    ->orderBy(
                        (string) config('filament-auditing.audits_sort.column', 'created_at'),
                        (string) config('filament-auditing.audits_sort.direction', 'desc'),
                    );
            })
            ->emptyStateHeading('No audit entries yet')
            ->columns(Arr::flatten([
                TextColumn::make('user.name')
                    ->label('User')
                    ->placeholder('System'),
                TextColumn::make('event')
                    ->label('Event')
                    ->formatStateUsing(static fn (?string $state): string => filled($state) ? Str::headline($state) : '—'),
                TextColumn::make('created_at')
                    ->since()
                    ->label('Recorded'),
                AuditValuesColumn::make('old_values')
                    ->label('Before')
                    ->formatStateUsing(fn (Column $column, Audit $record): mixed => AuditValuePresenter::view($record, $column->getName(), $this->getOwnerRecord())),
                AuditValuesColumn::make('new_values')
                    ->label('After')
                    ->formatStateUsing(fn (Column $column, Audit $record): mixed => AuditValuePresenter::view($record, $column->getName(), $this->getOwnerRecord())),
                self::extraColumns(),
            ]))
            ->filters(AuditFilters::configure())
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }

    #[\Override]
    protected function canCreate(): bool
    {
        return false;
    }

    #[\Override]
    protected function canEdit(Model $record): bool
    {
        return false;
    }

    #[\Override]
    protected function canDelete(Model $record): bool
    {
        return false;
    }
}
