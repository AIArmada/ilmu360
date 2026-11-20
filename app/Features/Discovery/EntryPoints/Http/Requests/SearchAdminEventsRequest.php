<?php

declare(strict_types=1);

namespace App\Features\Discovery\EntryPoints\Http\Requests;

use App\Features\Events\Api\AdminEventSearch;
use Illuminate\Foundation\Http\FormRequest;

class SearchAdminEventsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(AdminEventSearch $eventSearchService): array
    {
        return $eventSearchService->validationRules();
    }

    #[\Override]
    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (AdminEventSearch::ARRAY_FILTER_KEYS as $key) {
            $normalized[$key] = $this->normalizeArrayLike($this->query($key));
        }

        $this->merge($normalized);
    }

    /**
     * @return list<string>|null
     */
    private function normalizeArrayLike(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        $values = is_array($value)
            ? $value
            : explode(',', (string) $value);

        $normalized = collect($values)
            ->map(static fn (mixed $item): ?string => is_scalar($item) ? trim((string) $item) : null)
            ->filter(static fn (?string $item): bool => $item !== null && $item !== '')
            ->values()
            ->all();

        return $normalized === [] ? null : $normalized;
    }
}
