<?php

namespace App\Features\SlugRedirects\EntryPoints\Filament\Resources\SlugRedirects\Tables;

use App\Features\SlugRedirects\Api\SlugRedirectAdministration;
use App\Features\SlugRedirects\Models\SlugRedirect;
use App\Platform\Routing\PublicSlugRegistry;
use App\Platform\Routing\PublicSlugResource;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SlugRedirectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('redirectable')->orderByDesc('created_at'))
            ->columns([
                TextColumn::make('redirectable_type')
                    ->label('Subject Type')
                    ->badge()
                    ->formatStateUsing(static fn (?string $state): string => filled($state) ? Str::headline($state) : 'Unknown'),
                TextColumn::make('subject_label')
                    ->label('Subject')
                    ->state(static fn (SlugRedirect $record): string => self::subjectLabel($record->redirectable)),
                TextColumn::make('source_path')
                    ->label('From')
                    ->searchable(),
                TextColumn::make('destination_path')
                    ->label('To')
                    ->searchable(),
                TextColumn::make('first_visited_at')
                    ->label('First Visited')
                    ->since(),
                TextColumn::make('redirect_count')
                    ->label('Redirects')
                    ->numeric(),
                TextColumn::make('last_redirected_at')
                    ->label('Last Redirect')
                    ->since()
                    ->placeholder('Never'),
                TextColumn::make('created_at')
                    ->label('Recorded')
                    ->since(),
            ])
            ->filters([
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->using(function (array $data, SlugRedirect $record): SlugRedirect {
                        $redirectId = (string) $record->getKey();
                        app(SlugRedirectAdministration::class)->update(
                            $redirectId,
                            is_string($data['redirectable_type'] ?? null) ? $data['redirectable_type'] : null,
                            is_string($data['redirectable_id'] ?? null) ? $data['redirectable_id'] : null,
                            is_string($data['source_slug'] ?? null) ? $data['source_slug'] : null,
                        );

                        return SlugRedirect::query()->findOrFail($redirectId);
                    }),
                DeleteAction::make()
                    ->using(fn (SlugRedirect $record): bool => app(SlugRedirectAdministration::class)->delete((string) $record->getKey())),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords('delete')
                        ->using(function (DeleteBulkAction $action, Collection $records): void {
                            $deleted = app(SlugRedirectAdministration::class)->deleteMany(
                                $records
                                    ->map(static fn (Model $record): string => (string) $record->getKey())
                                    ->all(),
                            );

                            if ($deleted > 0) {
                                $action->reportBulkProcessingSuccessfulRecordsCount($deleted);
                            }

                            if ($deleted < $records->count()) {
                                $action->reportBulkProcessingFailure();
                            }
                        }),
                ]),
            ]);
    }

    private static function subjectLabel(?Model $model): string
    {
        if (! $model instanceof Model) {
            return 'Deleted record';
        }

        $registry = app(PublicSlugRegistry::class);

        return $registry->resourceForModel($model) instanceof PublicSlugResource
            ? $registry->labelFor($model)
            : 'Deleted record';
    }
}
