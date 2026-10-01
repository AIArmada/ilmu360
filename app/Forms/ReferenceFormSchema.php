<?php

namespace App\Forms;

use AIArmada\CommerceSupport\Support\ConnectionDriver;
use AIArmada\References\Enums\ReferenceRecordKind;
use AIArmada\References\Rules\Isbn;
use App\Actions\References\SaveReferenceAction;
use App\Enums\ReferencePartType;
use App\Enums\ReferenceType;
use App\Models\Reference;
use App\Services\Signals\ProductSignalsService;
use App\Support\Cache\SelectionCatalogCache;
use BackedEnum;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReferenceFormSchema
{
    /** @return array<int, Component> */
    public static function fields(string $yearField = 'year', bool $publicOnly = true): array
    {
        return [
            TextInput::make('title')->label(__('Title'))->live(onBlur: true)->required()->maxLength(255),
            Select::make('author_ids')->label(__('Authors'))
                ->helperText(__('Optional. Leave empty when the authors are unknown.'))
                ->multiple()->searchable()
                ->getSearchResultsUsing(fn (string $search): array => ReferenceAuthorFormSchema::searchOptions($search))
                ->getOptionLabelsUsing(fn (array $values): array => ReferenceAuthorFormSchema::selectedLabels($values))
                ->createOptionForm(ReferenceAuthorFormSchema::quickCreateForm())
                ->createOptionUsing(fn (array $data): string => ReferenceAuthorFormSchema::quickCreateUsing($data))
                ->formatStateUsing(fn (mixed $state, ?Reference $record): mixed => $record?->authorIdsValue() ?? $state)
                ->visible(fn (Get $get): bool => ! self::hasParent($get))
                ->dehydrated(fn (Get $get): bool => ! self::hasParent($get)),
            Placeholder::make('inherited_authors')->label(__('Authors (from parent work)'))
                ->content(fn (Get $get): string => self::inheritedAuthorNames($get, $publicOnly))
                ->visible(fn (Get $get): bool => self::hasParent($get)),
            Select::make('type')->label(__('Reference Type'))->options(ReferenceType::class)
                ->default(ReferenceType::Book->value)->required()->live()
                ->afterStateUpdated(function (Set $set, mixed $state): void {
                    if (self::value($state) !== ReferenceType::Book->value) {
                        $set('record_kind', ReferenceRecordKind::Work->value);
                        $set('parent_id', null);
                    }
                }),
            Select::make('record_kind')->label(__('Record Kind'))
                ->options(['work' => __('Work'), 'edition' => __('Edition'), 'part' => __('Part / Volume')])
                ->default('work')->required()->live()->dehydratedWhenHidden()
                ->visible(fn (Get $get): bool => self::value($get('type')) === 'book')
                ->afterStateUpdated(fn (Set $set) => $set('parent_id', null)),
            Select::make('parent_id')->label(__('Parent Work or Edition'))
                ->helperText(__('An edition belongs to a work. A part belongs to a work or a specific edition.'))
                ->searchable()->live()->required(fn (Get $get): bool => self::hasParent($get))
                ->getSearchResultsUsing(fn (string $search, Get $get, ?Reference $record): array => self::parentOptions($search, $get, $record, $publicOnly))
                ->getOptionLabelUsing(fn (mixed $value, Get $get, ?Reference $record): ?string => is_string($value) && Str::isUuid($value) ? self::parentQuery($get, $record, $publicOnly)->find($value)?->displayTitle() : null)
                ->visible(fn (Get $get): bool => self::hasParent($get))
                ->dehydratedWhenHidden()
                ->dehydrateStateUsing(fn (mixed $state, Get $get): mixed => self::hasParent($get) ? $state : null),
            TextInput::make('edition_number')->label(__('Edition Number'))->live(onBlur: true)->integer()->minValue(1)
                ->visible(fn (Get $get): bool => self::value($get('record_kind')) === 'edition')
                ->dehydrated(fn (Get $get): bool => self::value($get('record_kind')) === 'edition'),
            TextInput::make('edition_label')->label(__('Edition Label'))->live(onBlur: true)->maxLength(255)
                ->required(fn (Get $get): bool => self::value($get('record_kind')) === 'edition'
                    && blank($get('edition_number')) && blank($get('publisher')) && blank($get($yearField)) && blank($get('isbn')))
                ->helperText(__('Optional label, such as Revised edition or Cetakan ketiga.'))
                ->visible(fn (Get $get): bool => self::value($get('record_kind')) === 'edition')
                ->dehydrated(fn (Get $get): bool => self::value($get('record_kind')) === 'edition'),
            Select::make('part_type')->label(__('Section Type'))->options(ReferencePartType::class)->live()
                ->default(ReferencePartType::Jilid->value)->required(fn (Get $get): bool => self::isPart($get))
                ->formatStateUsing(fn (mixed $state, ?Reference $record): mixed => $record?->partTypeValue() ?? $state)
                ->visible(fn (Get $get): bool => self::isPart($get))->dehydrated(fn (Get $get): bool => self::isPart($get)),
            TextInput::make('part_number')->label(__('Number'))->placeholder('2')->live(onBlur: true)->maxLength(255)
                ->required(fn (Get $get): bool => self::isPart($get) && blank($get('part_label')))
                ->formatStateUsing(fn (?string $state, ?Reference $record): ?string => $record?->partNumberValue() ?? $state)
                ->visible(fn (Get $get): bool => self::isPart($get))->dehydrated(fn (Get $get): bool => self::isPart($get)),
            TextInput::make('part_label')->label(__('Custom Name'))->placeholder(__('Chapter on Purification'))->helperText(__('Optional. Replaces the numbered label in the displayed title.'))->live(onBlur: true)->maxLength(255)
                ->formatStateUsing(fn (?string $state, ?Reference $record): ?string => $record?->partLabelValue() ?? $state)
                ->visible(fn (Get $get): bool => self::isPart($get))->dehydrated(fn (Get $get): bool => self::isPart($get)),
            TextInput::make($yearField)->label(__('Publication Year'))->live(onBlur: true)->integer()->minValue(-3000)->maxValue(now()->year + 5),
            TextInput::make('publisher')->label(__('Publisher'))->live(onBlur: true)->maxLength(255),
            TextInput::make('isbn')->label('ISBN')->maxLength(20)->rules([new Isbn]),
            Select::make('language')->label(__('Language'))
                ->placeholder(__('Select language'))
                ->options(fn (): array => app(SelectionCatalogCache::class)->languageSelectOptions())
                ->searchable()
                ->preload()
                ->getOptionLabelUsing(fn (mixed $value): ?string => is_string($value) && $value !== ''
                    ? (app(SelectionCatalogCache::class)->languageLabel($value) ?? $value)
                    : null),
            TextInput::make('url')->label(__('Primary URL'))->url()->rules(['url:http,https'])->maxLength(255),
        ];
    }

    /** @return array<int, Component> */
    public static function quickCreateComponents(): array
    {
        return [
            Section::make(__('Reference Details'))->schema([
                ...self::fields('publication_year'),
                Placeholder::make('reference_title_preview')->label(__('Reference Preview'))
                    ->content(fn (Get $get): string => self::previewTitle($get()))
                    ->visible(fn (Get $get): bool => filled($get('title')))->columnSpanFull(),
                Textarea::make('description')->label(__('Description'))->rows(3)->columnSpanFull(),
            ])->columns(2),
            Section::make(__('Imagery'))->schema(self::mediaFields())->columns(2),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function previewTitle(array $data): string
    {
        $reference = new Reference;
        $reference->fill([
            'title' => $data['title'] ?? '',
            'record_kind' => self::value($data['record_kind'] ?? 'work'),
            'edition_number' => $data['edition_number'] ?? null,
            'edition_label' => $data['edition_label'] ?? null,
            'publisher' => $data['publisher'] ?? null,
            'year' => $data['publication_year'] ?? null,
            'reference_parts' => [[
                'type' => self::value($data['part_type'] ?? ReferencePartType::Jilid->value),
                'value' => $data['part_number'] ?? null,
                'label' => $data['part_label'] ?? null,
            ]],
        ]);
        $parentId = $data['parent_id'] ?? null;
        $parent = is_string($parentId) && Str::isUuid($parentId)
            ? Reference::query()->active()->find($parentId)
            : null;
        $reference->setRelation('parentReference', $parent);

        return $reference->displayTitle();
    }

    /** @return array<int, Component> */
    public static function mediaFields(): array
    {
        return [
            ...array_map(fn (string $collection): SpatieMediaLibraryFileUpload => SpatieMediaLibraryFileUpload::make($collection)
                ->label($collection === 'front_cover' ? __('Front Cover') : __('Back Cover'))
                ->collection($collection)->image()->imageEditor()->imageAspectRatio('3:4')
                ->automaticallyOpenImageEditorForAspectRatio()->imageEditorAspectRatioOptions(['3:4'])
                ->automaticallyCropImagesToAspectRatio()->conversion('thumb')->responsiveImages(), ['front_cover', 'back_cover']),
            SpatieMediaLibraryFileUpload::make('gallery')->label(__('Gallery'))->collection('gallery')
                ->multiple()->reorderable()->image()->conversion('gallery_thumb')->responsiveImages()->columnSpanFull(),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function createPending(array $data, ?Schema $schema = null): string
    {
        try {
            $reference = app(SaveReferenceAction::class)->handle([...$data, 'status' => 'pending']);
        } catch (ValidationException $exception) {
            if ($schema instanceof Schema) {
                self::throwSchemaErrors($exception, $schema);
            }
            throw $exception;
        }
        $schema?->model($reference)->saveRelationships();
        app(ProductSignalsService::class)->recordReferenceQuickCreated($reference, request());

        return (string) $reference->getKey();
    }

    /**
     * Server-side guard for the direct admin pages (which bypass the save
     * action): language codes must exist and author IDs must be visible persons.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public static function validateDirectInput(array $data): void
    {
        Validator::make($data, [
            'language' => ['nullable', 'string', 'max:10', Rule::exists('languages', 'code')],
            'author_ids' => ['nullable', 'array'],
            'author_ids.*' => ['uuid', Rule::exists('persons', 'id')->whereIn('status', ['verified', 'pending'])],
        ])->validate();
    }

    public static function throwSchemaErrors(ValidationException $exception, Schema $schema): never
    {
        $path = $schema->getStatePath();
        $errors = [];
        foreach ($exception->errors() as $field => $messages) {
            $errors[filled($path) ? $path.'.'.$field : $field] = $messages;
        }
        throw ValidationException::withMessages($errors);
    }

    /** @return array<string, string> */
    public static function searchOptions(string $search): array
    {
        return self::labels(Reference::query()->active()->with('parentReference.parentReference')
            ->where(fn (Builder $query) => self::applySearch($query, $search))
            ->orderBy('title')->limit(50)->get());
    }

    /** @param array<mixed> $values
     * @return array<string, string>
     */
    public static function selectedLabels(array $values): array
    {
        $values = array_values(array_filter($values, fn (mixed $value): bool => is_string($value) && Str::isUuid($value)));
        if ($values === []) {
            return [];
        }

        return self::labels(Reference::query()->active()->with('parentReference.parentReference')->whereKey($values)->get());
    }

    /** @param iterable<Reference> $references
     * @return array<string, string>
     */
    private static function labels(iterable $references): array
    {
        $labels = [];
        foreach ($references as $reference) {
            $labels[(string) $reference->getKey()] = $reference->displayTitle();
        }

        return $labels;
    }

    /** @return Builder<Reference> */
    private static function parentQuery(Get $get, ?Reference $record, bool $publicOnly): Builder
    {
        return Reference::query()->when($publicOnly, fn (Builder $query) => $query->active())->with('parentReference.parentReference')->where('type', 'book')
            ->whereIn('record_kind', self::value($get('record_kind')) === 'edition' ? ['work'] : ['work', 'edition'])
            ->when($record?->exists, fn (Builder $query) => $query->whereKeyNot($record->getKey()));
    }

    /** @return array<string, string> */
    private static function parentOptions(string $search, Get $get, ?Reference $record, bool $publicOnly): array
    {
        return self::labels(self::parentQuery($get, $record, $publicOnly)->where(fn (Builder $query) => self::applySearch($query, $search))->orderBy('title')->limit(50)->get());
    }

    private static function inheritedAuthorNames(Get $get, bool $publicOnly): string
    {
        $parentId = $get('parent_id');

        if (! is_string($parentId) || ! Str::isUuid($parentId)) {
            return __('Select a parent work or edition to see its authors.');
        }

        $parent = Reference::query()
            ->when($publicOnly, fn (Builder $query) => $query->active())
            ->withEffectiveAuthors()
            ->find($parentId);

        if (! $parent instanceof Reference) {
            return __('Unknown authors');
        }

        $names = $parent->effectiveAuthorNames();

        return $names !== '' ? $names : __('Unknown authors');
    }

    /** @param Builder<Reference> $query */
    private static function applySearch(Builder $query, string $search): void
    {
        $term = '%'.$search.'%';
        $query->where('title', 'like', $term)->orWhere('isbn', 'like', $term)
            ->orWhere('edition_label', 'like', $term)->orWhere('publisher', 'like', $term)
            ->orWhereRaw('CAST('.$query->getQuery()->getGrammar()->wrap('year').' AS '.(ConnectionDriver::name($query->getConnection()) === 'mysql' ? 'CHAR' : 'TEXT').') LIKE ?', [$term]);
        $query->orWherePartTextLike($term);
    }

    private static function hasParent(Get $get): bool
    {
        return self::value($get('type')) === 'book' && in_array(self::value($get('record_kind')), ['edition', 'part'], true);
    }

    private static function isPart(Get $get): bool
    {
        return self::hasParent($get) && self::value($get('record_kind')) === 'part';
    }

    private static function value(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }
}
