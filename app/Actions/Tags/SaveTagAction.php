<?php

declare(strict_types=1);

namespace App\Actions\Tags;

use App\Enums\TagType;
use App\Models\Tag;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class SaveTagAction
{
    use AsAction;

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?Tag $tag = null): Tag
    {
        $tag ??= new Tag;

        $attributes = [
            'name' => $this->normalizeNameTranslations($data['name'] ?? $tag->name),
            'type' => $this->normalizeType($data['type'] ?? $tag->type ?? null),
            'status' => $this->normalizeStatus($data['status'] ?? $tag->status ?? 'verified'),
            'order_column' => $this->normalizeOrderColumn($data['order_column'] ?? $tag->order_column ?? null),
        ];

        $tag->fill($attributes);
        $tag->save();

        return $tag->fresh() ?? $tag;
    }

    /**
     * @return array{ms: string, en: string}
     */
    private function normalizeNameTranslations(mixed $value): array
    {
        if (! is_array($value)) {
            throw ValidationException::withMessages([
                'name' => __('The tag name translations are invalid.'),
            ]);
        }

        $malay = $this->normalizeRequiredString($value['ms'] ?? null, 'name.ms');
        $english = $this->normalizeOptionalString($value['en'] ?? null) ?? $malay;

        return [
            'ms' => $malay,
            'en' => $english,
        ];
    }

    private function normalizeType(mixed $value): string
    {
        $type = $value instanceof TagType ? $value : TagType::tryFrom((string) $value);

        if (! $type instanceof TagType) {
            throw ValidationException::withMessages([
                'type' => __('The selected tag type is invalid.'),
            ]);
        }

        return $type->value;
    }

    private function normalizeStatus(mixed $value): string
    {
        $status = is_scalar($value) ? trim((string) $value) : '';

        if (! in_array($status, ['pending', 'verified'], true)) {
            throw ValidationException::withMessages([
                'status' => __('The selected tag status is invalid.'),
            ]);
        }

        return $status;
    }

    private function normalizeOrderColumn(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw ValidationException::withMessages([
                'order_column' => __('The sort order must be an integer.'),
            ]);
        }

        return (int) $value;
    }

    private function normalizeRequiredString(mixed $value, string $key): string
    {
        $normalized = $this->normalizeOptionalString($value);

        if ($normalized === null) {
            throw ValidationException::withMessages([
                $key => __('This field is required.'),
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
