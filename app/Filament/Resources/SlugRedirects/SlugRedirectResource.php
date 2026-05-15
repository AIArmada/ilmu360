<?php

namespace App\Filament\Resources\SlugRedirects;

use App\Filament\Resources\SlugRedirects\Pages\CreateSlugRedirect;
use App\Filament\Resources\SlugRedirects\Pages\EditSlugRedirect;
use App\Filament\Resources\SlugRedirects\Pages\ListSlugRedirects;
use App\Filament\Resources\SlugRedirects\Pages\ViewSlugRedirect;
use App\Filament\Resources\SlugRedirects\Schemas\SlugRedirectForm;
use App\Filament\Resources\SlugRedirects\Schemas\SlugRedirectInfolist;
use App\Filament\Resources\SlugRedirects\Tables\SlugRedirectsTable;
use App\Models\SlugRedirect;
use App\Support\Slugs\PublicSlugPathResolver;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class SlugRedirectResource extends Resource
{
    protected static ?string $model = SlugRedirect::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $modelLabel = 'Slug Redirect';

    protected static ?string $pluralModelLabel = 'Slug Redirects';

    protected static ?string $navigationLabel = 'Slug Redirects';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return SlugRedirectForm::configure($schema);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return SlugRedirectInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return SlugRedirectsTable::configure($table);
    }

    #[\Override]
    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListSlugRedirects::route('/'),
            'create' => CreateSlugRedirect::route('/create'),
            'view' => ViewSlugRedirect::route('/{record}'),
            'edit' => EditSlugRedirect::route('/{record}/edit'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function mutateRedirectData(array $data, ?SlugRedirect $record = null): array
    {
        $existingRedirectCount = $record instanceof SlugRedirect ? $record->redirect_count : 0;
        $type = self::normalizeRedirectableType(Arr::get($data, 'redirectable_type'));
        $redirectableId = Arr::get($data, 'redirectable_id');
        $sourceSlug = self::normalizeSlug(Arr::get($data, 'source_slug'));
        $redirectable = self::resolveRedirectable($type, $redirectableId);
        $errors = [];

        if ($type === null) {
            $errors['redirectable_type'] = 'Select a supported subject type.';
        }

        if (! $redirectable instanceof Model) {
            $errors['redirectable_id'] = 'Select a valid subject.';
        }

        if ($sourceSlug === null) {
            $errors['source_slug'] = 'Enter the old source slug to redirect.';
        }

        $destinationSlug = $redirectable instanceof Model
            ? self::normalizeSlug($redirectable->getAttribute('slug'))
            : null;
        $destinationPath = $redirectable instanceof Model
            ? app(PublicSlugPathResolver::class)->pathForModel($redirectable)
            : null;
        $sourcePath = ($type !== null && $sourceSlug !== null)
            ? app(PublicSlugPathResolver::class)->pathForParameter($type, $sourceSlug)
            : null;

        if ($redirectable instanceof Model && $destinationSlug === null) {
            $errors['redirectable_id'] = 'The selected subject does not have a current canonical slug.';
        }

        if ($redirectable instanceof Model && $destinationPath === null) {
            $errors['redirectable_id'] = 'The selected subject does not expose a canonical public URL.';
        }

        if ($sourceSlug !== null && $destinationSlug !== null && $sourceSlug === $destinationSlug) {
            $errors['source_slug'] = 'The source slug must differ from the selected subject\'s current canonical slug.';
        }

        if ($sourcePath !== null && $destinationPath !== null && $sourcePath === $destinationPath) {
            $errors['source_slug'] = 'The source slug resolves to the same public path as the destination.';
        }

        if ($sourcePath !== null) {
            $query = SlugRedirect::query()->where('source_path', $sourcePath);

            if ($record?->exists) {
                $query->whereKeyNot($record->getKey());
            }

            if ($query->exists()) {
                $errors['source_slug'] = 'Another redirect already uses this public source path.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            ...$data,
            'redirectable_type' => $type,
            'redirectable_id' => (string) $redirectable?->getKey(),
            'source_slug' => $sourceSlug,
            'source_path' => $sourcePath,
            'destination_slug' => $destinationSlug,
            'destination_path' => $destinationPath,
            'redirect_count' => max(0, (int) Arr::get($data, 'redirect_count', $existingRedirectCount)),
        ];
    }

    private static function normalizeRedirectableType(mixed $type): ?string
    {
        if (! is_string($type) || trim($type) === '') {
            return null;
        }

        $type = trim($type);

        if (Relation::getMorphedModel($type) !== null) {
            return $type;
        }

        if (class_exists($type) && is_subclass_of($type, Model::class)) {
            $model = new $type;

            return $model->getMorphClass();
        }

        return null;
    }

    private static function resolveRedirectable(?string $type, mixed $redirectableId): ?Model
    {
        if ($type === null || blank($redirectableId)) {
            return null;
        }

        $modelClass = Relation::getMorphedModel($type);

        if (! is_string($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
            return null;
        }

        $record = $modelClass::query()->find($redirectableId);

        return $record instanceof Model ? $record : null;
    }

    private static function normalizeSlug(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $slug = Str::lower(trim($value));

        return $slug !== '' ? $slug : null;
    }
}
