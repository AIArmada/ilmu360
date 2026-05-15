<?php

namespace App\Filament\Resources\SlugRedirects\Schemas;

use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\SlugRedirect;
use App\Models\Speaker;
use App\Models\Venue;
use App\Support\Slugs\PublicSlugPathResolver;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

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
        return [
            'event' => 'Event',
            'institution' => 'Institution',
            'speaker' => 'Speaker',
            'venue' => 'Venue',
            'reference' => 'Reference',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function redirectableSearchResults(mixed $type, ?string $search = null): array
    {
        $modelClass = self::redirectableModelClass($type);

        if ($modelClass === null) {
            return [];
        }

        $query = $modelClass::query();
        $term = trim((string) $search);

        if ($term !== '') {
            match ($type) {
                'event' => $query->where(function ($query) use ($term): void {
                    $query
                        ->where('title', 'like', "%{$term}%")
                        ->orWhere('slug', 'like', "%{$term}%");
                }),
                'reference' => $query->where(function ($query) use ($term): void {
                    $query
                        ->where('title', 'like', "%{$term}%")
                        ->orWhere('slug', 'like', "%{$term}%");
                }),
                'speaker' => $query->where(function ($query) use ($term): void {
                    $query
                        ->where('name', 'like', "%{$term}%")
                        ->orWhere('slug', 'like', "%{$term}%");
                }),
                default => $query->where(function ($query) use ($term): void {
                    $query
                        ->where('name', 'like', "%{$term}%")
                        ->orWhere('slug', 'like', "%{$term}%");
                }),
            };
        }

        return $query
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Model $record): array => [
                (string) $record->getKey() => self::redirectableLabel($record),
            ])
            ->all();
    }

    public static function redirectableOptionLabel(mixed $type, mixed $value): ?string
    {
        $modelClass = self::redirectableModelClass($type);

        if ($modelClass === null || blank($value)) {
            return null;
        }

        $record = $modelClass::query()->find($value);

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

        return app(PublicSlugPathResolver::class)->pathForParameter($type, $slug)
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

        return app(PublicSlugPathResolver::class)->pathForModel($model)
            ?? 'The selected subject does not currently have a canonical public slug path.';
    }

    private static function redirectableModelClass(mixed $type): ?string
    {
        if (! is_string($type) || trim($type) === '') {
            return null;
        }

        $type = trim($type);

        if (class_exists($type) && is_subclass_of($type, Model::class)) {
            /** @var class-string<Model> $type */
            return $type;
        }

        $modelClass = Relation::getMorphedModel($type);

        return is_string($modelClass) && is_subclass_of($modelClass, Model::class)
            ? $modelClass
            : null;
    }

    private static function selectedRedirectableModel(mixed $type, mixed $id): ?Model
    {
        $modelClass = self::redirectableModelClass($type);

        if ($modelClass === null || blank($id)) {
            return null;
        }

        $record = $modelClass::query()->find($id);

        return $record instanceof Model ? $record : null;
    }

    private static function redirectableLabel(Model $record): string
    {
        $name = match (true) {
            $record instanceof Event => $record->title,
            $record instanceof Institution => $record->name,
            $record instanceof Speaker => $record->formatted_name,
            $record instanceof Venue => $record->name,
            $record instanceof Reference => $record->title,
            default => class_basename($record::class).' #'.$record->getKey(),
        };

        $slug = $record->getAttribute('slug');

        return filled($slug)
            ? "{$name} ({$slug})"
            : $name;
    }
}
