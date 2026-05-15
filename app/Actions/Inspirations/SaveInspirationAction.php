<?php

declare(strict_types=1);

namespace App\Actions\Inspirations;

use App\Enums\InspirationCategory;
use App\Models\Inspiration;
use App\Support\Media\ModelMediaSyncService;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class SaveInspirationAction
{
    use AsAction;

    public function __construct(
        private ModelMediaSyncService $mediaSyncService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?Inspiration $inspiration = null): Inspiration
    {
        $creating = ! $inspiration instanceof Inspiration;
        $inspiration ??= new Inspiration;

        $inspiration->fill([
            'category' => array_key_exists('category', $data)
                ? $this->normalizeCategory($data['category'])
                : $this->normalizeCategory($inspiration->category ?? ($creating ? InspirationCategory::QuranQuote->value : null)),
            'locale' => array_key_exists('locale', $data)
                ? $this->normalizeLocale($data['locale'])
                : $this->normalizeLocale($inspiration->locale ?? ($creating ? $this->defaultLocale() : null)),
            'title' => $this->normalizeRequiredString($data['title'] ?? $inspiration->title, 'title'),
            'content' => array_key_exists('content', $data)
                ? Inspiration::normalizeRichContent($data['content'])
                : Inspiration::normalizeRichContent($inspiration->content ?? ''),
            'source' => array_key_exists('source', $data)
                ? $this->normalizeOptionalString($data['source'])
                : $inspiration->source,
            'is_active' => array_key_exists('is_active', $data)
                ? (bool) $data['is_active']
                : ($creating ? true : (bool) $inspiration->is_active),
        ]);

        $inspiration->save();

        if (($data['clear_main'] ?? false) === true) {
            $this->mediaSyncService->clearCollection($inspiration, 'main');
        }

        $main = $data['main'] ?? null;

        $this->mediaSyncService->syncSingle(
            $inspiration,
            $main instanceof UploadedFile ? $main : null,
            'main',
        );

        return $inspiration->fresh(['media']) ?? $inspiration;
    }

    private function normalizeCategory(mixed $value): string
    {
        $category = $value instanceof InspirationCategory ? $value : InspirationCategory::tryFrom((string) $value);

        if (! $category instanceof InspirationCategory) {
            throw ValidationException::withMessages([
                'category' => __('The selected inspiration category is invalid.'),
            ]);
        }

        return $category->value;
    }

    private function normalizeLocale(mixed $value): string
    {
        $locale = $this->normalizeRequiredString($value, 'locale');

        validator(['locale' => $locale], [
            'locale' => ['required', 'string', Rule::in($this->supportedLocales())],
        ])->validate();

        return $locale;
    }

    private function defaultLocale(): string
    {
        $supportedLocales = $this->supportedLocales();
        $appLocale = app()->getLocale();

        if (in_array($appLocale, $supportedLocales, true)) {
            return $appLocale;
        }

        return $supportedLocales[0] ?? 'ms';
    }

    /**
     * @return list<string>
     */
    private function supportedLocales(): array
    {
        $locales = config('app.supported_locales', []);

        if (! is_array($locales) || $locales === []) {
            return ['ms'];
        }

        if (array_is_list($locales)) {
            return array_values(array_filter($locales, static fn (mixed $locale): bool => is_string($locale) && $locale !== ''));
        }

        return array_values(array_filter(array_keys($locales), static fn (mixed $locale): bool => is_string($locale) && $locale !== ''));
    }

    private function normalizeRequiredString(mixed $value, string $field): string
    {
        $normalized = $this->normalizeOptionalString($value);

        if ($normalized === null) {
            throw ValidationException::withMessages([
                $field => __('This field is required.'),
            ]);
        }

        return $normalized;
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
