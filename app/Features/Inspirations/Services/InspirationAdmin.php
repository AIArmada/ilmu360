<?php

declare(strict_types=1);

namespace App\Features\Inspirations\Services;

use App\Features\Inspirations\Actions\SaveInspirationAction;
use App\Features\Inspirations\Api\InspirationAdminContract;
use App\Features\Inspirations\Api\InspirationSnapshot;
use App\Features\Inspirations\Enums\InspirationCategory;
use App\Features\Inspirations\Models\Inspiration;
use App\Platform\Admin\Concerns\BuildsAdminFieldMetadata;
use BackedEnum;
use Illuminate\Validation\Rule;

final readonly class InspirationAdmin implements InspirationAdminContract
{
    use BuildsAdminFieldMetadata;

    public function __construct(
        private SaveInspirationAction $saveInspirationAction,
    ) {}

    public function modelClass(): string
    {
        return Inspiration::class;
    }

    public function fields(): array
    {
        return [
            $this->field('category', 'string', required: true, default: InspirationCategory::QuranQuote->value, allowedValues: $this->enumValues(InspirationCategory::class)),
            $this->field('locale', 'string', required: true, default: $this->defaultSupportedLocale(), allowedValues: $this->supportedLocaleValues()),
            $this->field('title', 'string', required: true, maxLength: 255),
            $this->field('content', 'rich_text', required: true, meta: [
                'input_normalization' => [
                    'kind' => 'rich_text_document',
                    'accepts_plain_string' => true,
                ],
            ]),
            $this->field('source', 'string', required: false, maxLength: 255, meta: $this->trimmedStringMutationMeta()),
            $this->field('status', 'string', required: false, default: 'active', allowedValues: ['active', 'inactive']),
            $this->field('main', 'file', required: false, acceptedMimeTypes: $this->imageMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb(), meta: $this->singleMediaFieldMutationMeta('clear_main')),
            $this->field('clear_main', 'boolean', required: false, default: false),
        ];
    }

    public function rules(bool $updating = false): array
    {
        $required = $updating ? 'required' : 'required';
        $maxUploadSize = 'max:'.$this->maxUploadSizeKb();

        return [
            'category' => [$required, Rule::enum(InspirationCategory::class)],
            'locale' => [$required, 'string', Rule::in($this->supportedLocaleValues())],
            'title' => [$required, 'string', 'max:255'],
            'content' => [$required],
            'source' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'main' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp', $maxUploadSize],
            'clear_main' => ['sometimes', 'boolean'],
        ];
    }

    public function createDefaults(): array
    {
        return [
            'category' => InspirationCategory::QuranQuote->value,
            'locale' => $this->defaultSupportedLocale(),
            'title' => '',
            'content' => Inspiration::plainTextToRichContent(''),
            'source' => null,
            'status' => 'active',
            'clear_main' => false,
        ];
    }

    public function defaultsFor(string $inspirationId): array
    {
        $record = Inspiration::query()->findOrFail($inspirationId);
        $category = $record->category;

        return [
            'category' => $category instanceof InspirationCategory
                ? $category->value
                : (is_string($category) && $category !== '' ? $category : InspirationCategory::QuranQuote->value),
            'locale' => (string) $record->locale,
            'title' => $record->title,
            'content' => $record->content,
            'source' => $record->source,
            'status' => (string) $record->status,
            'clear_main' => false,
        ];
    }

    public function mediaCollections(): array
    {
        return ['main'];
    }

    public function normalize(array $payload, ?string $inspirationId = null): array
    {
        return $payload;
    }

    public function save(array $payload, ?string $inspirationId = null): InspirationSnapshot
    {
        $inspiration = $inspirationId !== null
            ? Inspiration::query()->findOrFail($inspirationId)
            : null;

        $saved = $this->saveInspirationAction->handle($payload, $inspiration);

        return new InspirationSnapshot(
            id: (string) $saved->getKey(),
            category: $saved->category instanceof InspirationCategory ? $saved->category->value : (string) $saved->category,
            locale: (string) $saved->locale,
            title: (string) $saved->title,
            source: is_string($saved->source) ? $saved->source : null,
            status: (string) $saved->status,
        );
    }

    /**
     * @return list<string|int>
     */
    private function enumValues(string $enumClass): array
    {
        return array_map(
            static fn (BackedEnum $case): string|int => $case->value,
            $enumClass::cases(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function singleMediaFieldMutationMeta(string $rawHttpClearFlag): array
    {
        return [
            'mutation_semantics' => 'replace_single_media_collection',
            'clear_semantics' => [
                'omitted' => 'preserve_existing_collection',
                'explicit_null' => 'preserve_existing_collection',
                'submitted_file' => 'replace_collection',
            ],
            'raw_http_clear_flag' => $rawHttpClearFlag,
        ];
    }

    /**
     * @return list<string>
     */
    private function imageMimeTypes(): array
    {
        return ['image/jpeg', 'image/png', 'image/webp'];
    }

    /**
     * @return list<string>
     */
    private function supportedLocaleValues(): array
    {
        $locales = config('app.supported_locales', []);

        if (! is_array($locales) || $locales === []) {
            return [app()->getLocale() ?: 'ms'];
        }

        if (array_is_list($locales)) {
            return array_values(array_filter($locales, static fn (mixed $locale): bool => is_string($locale) && $locale !== ''));
        }

        return array_values(array_filter(array_keys($locales), static fn (mixed $locale): bool => is_string($locale) && $locale !== ''));
    }

    private function defaultSupportedLocale(): string
    {
        $supportedLocales = $this->supportedLocaleValues();
        $appLocale = app()->getLocale();

        if (is_string($appLocale) && in_array($appLocale, $supportedLocales, true)) {
            return $appLocale;
        }

        return $supportedLocales[0] ?? 'ms';
    }
}
