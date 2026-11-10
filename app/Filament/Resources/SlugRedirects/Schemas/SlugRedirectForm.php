<?php

namespace App\Filament\Resources\SlugRedirects\Schemas;

use App\Models\SlugRedirect;
use App\Platform\Routing\PublicSlugRegistry;
use App\Platform\Routing\PublicSlugResource;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SlugRedirectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Redirect')
                    ->schema([
                        Select::make('redirectable_type')
                            ->label('Subject Type')
                            ->options(self::redirectableTypeOptions())
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set, mixed $state, ?SlugRedirect $record): void {
                                if ($state !== $record?->redirectable_type) {
                                    $set('redirectable_id', null);
                                }
                            }),
                        Select::make('redirectable_id')
                            ->label('Subject')
                            ->searchable()
                            ->required()
                            ->disabled(fn (Get $get): bool => blank($get('redirectable_type')))
                            ->getSearchResultsUsing(fn (Get $get, ?string $search): array => self::redirectableSearchResults(
                                $get('redirectable_type'),
                                $search,
                            ))
                            ->getOptionLabelUsing(fn (Get $get, mixed $value): ?string => self::redirectableOptionLabel(
                                $get('redirectable_type'),
                                $value,
                            )),
                        TextInput::make('source_slug')
                            ->label('Source Slug')
                            ->required()
                            ->maxLength(255)
                            ->helperText('This old public slug will redirect to the selected subject\'s current canonical URL.'),
                        Placeholder::make('source_path_preview')
                            ->label('Derived Source Path')
                            ->content(fn (Get $get, ?SlugRedirect $record): string => self::sourcePathPreview($get, $record)),
                        Placeholder::make('destination_path_preview')
                            ->label('Canonical Destination')
                            ->content(fn (Get $get, ?SlugRedirect $record): string => self::destinationPathPreview($get, $record)),
                    ])
                    ->columns(2),
                Section::make('Tracking')
                    ->schema([
                        DateTimePicker::make('first_visited_at')
                            ->label('First Visited'),
                        DateTimePicker::make('last_redirected_at')
                            ->label('Last Redirect'),
                        TextInput::make('redirect_count')
                            ->label('Redirect Count')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->required(),
                    ])
                    ->columns(3),
            ]);
    }

    /**
     * @return array<string, string>
     */
    public static function redirectableTypeOptions(): array
    {
        return collect(app(PublicSlugRegistry::class)->resources())
            ->mapWithKeys(static fn (PublicSlugResource $resource): array => [
                $resource->parameter => Str::headline($resource->parameter),
            ])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function redirectableSearchResults(mixed $type, ?string $search = null): array
    {
        $resource = self::redirectableResource($type);

        if (! $resource instanceof PublicSlugResource) {
            return [];
        }

        $query = (new $resource->modelClass)->newQuery();
        $term = trim((string) $search);

        if ($term !== '' && $resource->searchColumns !== []) {
            $query->where(static function (Builder $query) use ($term, $resource): void {
                foreach ($resource->searchColumns as $index => $column) {
                    if ($index === 0) {
                        $query->whereLike($column, "%{$term}%");

                        continue;
                    }

                    $query->orWhereLike($column, "%{$term}%");
                }
            });
        }

        return $query
            ->limit(50)
            ->get()
            ->mapWithKeys(static fn (Model $record): array => [
                (string) $record->getKey() => self::redirectableLabel($record),
            ])
            ->all();
    }

    public static function redirectableOptionLabel(mixed $type, mixed $value): ?string
    {
        $resource = self::redirectableResource($type);

        if (! $resource instanceof PublicSlugResource || blank($value)) {
            return null;
        }

        $record = $resource->modelClass::query()->find($value);

        if (! $record instanceof Model) {
            return "Deleted record ({$value})";
        }

        return self::redirectableLabel($record);
    }

    public static function sourcePathPreview(Get $get, ?SlugRedirect $record): string
    {
        $type = $get('redirectable_type') ?: $record?->redirectable_type;
        $slug = trim((string) ($get('source_slug') ?: $record?->source_slug));

        if (! is_string($type) || $type === '' || $slug === '') {
            return 'Select a subject and enter a source slug to preview the public path.';
        }

        return app(PublicSlugRegistry::class)->pathForParameter($type, $slug)
            ?? 'The selected subject type does not expose a public slug route.';
    }

    public static function destinationPathPreview(Get $get, ?SlugRedirect $record): string
    {
        $model = self::selectedRedirectableModel(
            $get('redirectable_type') ?: $record?->redirectable_type,
            $get('redirectable_id') ?: $record?->redirectable_id,
        );

        if (! $model instanceof Model) {
            return 'Select a subject to preview its current canonical destination URL.';
        }

        return app(PublicSlugRegistry::class)->pathForModel($model)
            ?? 'The selected subject does not currently have a canonical public slug path.';
    }

    private static function redirectableResource(mixed $type): ?PublicSlugResource
    {
        if (! is_string($type) || trim($type) === '') {
            return null;
        }

        return app(PublicSlugRegistry::class)->resourceForType(trim($type));
    }

    private static function selectedRedirectableModel(mixed $type, mixed $id): ?Model
    {
        $resource = self::redirectableResource($type);

        if (! $resource instanceof PublicSlugResource || blank($id)) {
            return null;
        }

        $record = $resource->modelClass::query()->find($id);

        return $record instanceof Model ? $record : null;
    }

    private static function redirectableLabel(Model $record): string
    {
        $label = app(PublicSlugRegistry::class)->labelFor($record);
        $slug = $record->getAttribute('slug');

        return is_string($slug) && trim($slug) !== ''
            ? "{$label} (".trim($slug).')'
            : $label;
    }
}
