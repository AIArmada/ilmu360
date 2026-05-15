<?php

namespace App\Filament\Resources\Audits\Tables;

use App\Models\Audit;
use App\Support\Auditing\AuditValuePresenter;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Tapp\FilamentAuditing\Concerns\HasExtraColumns;
use Tapp\FilamentAuditing\Filament\Resources\Audits\Schemas\AuditFilters;
use Tapp\FilamentAuditing\Filament\Tables\Columns\AuditValuesColumn;

class AuditsTable
{
    use HasExtraColumns;

    public static function configure(Table $table): Table
    {
        return $table
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
                TextColumn::make('auditable_type')
                    ->label('Record Type')
                    ->formatStateUsing(static fn (?string $state): string => filled($state) ? Str::headline(Str::afterLast($state, '\\')) : '—'),
                TextColumn::make('event')
                    ->label('Event')
                    ->formatStateUsing(static fn (?string $state): string => filled($state) ? Str::headline($state) : '—'),
                TextColumn::make('created_at')
                    ->since()
                    ->label('Recorded'),
                AuditValuesColumn::make('old_values')
                    ->label('Before')
                    ->formatStateUsing(static fn (Column $column, Audit $record): mixed => AuditValuePresenter::view($record, $column->getName())),
                AuditValuesColumn::make('new_values')
                    ->label('After')
                    ->formatStateUsing(static fn (Column $column, Audit $record): mixed => AuditValuePresenter::view($record, $column->getName())),
                self::extraColumns(),
            ]))
            ->filters(AuditFilters::configure())
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }
}
