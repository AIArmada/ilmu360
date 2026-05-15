<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Admin;

use App\Support\Mcp\McpEventSearchService;
use Illuminate\Foundation\Http\FormRequest;

class SearchAdminEventsRequest extends FormRequest
{
    /**
     * @var list<string>
     */
    private const array ARRAY_FILTER_KEYS = [
        'language_codes',
        'event_type',
        'age_group',
        'event_format',
        'speaker_ids',
        'key_person_roles',
        'person_in_charge_ids',
        'moderator_ids',
        'imam_ids',
        'khatib_ids',
        'bilal_ids',
        'topic_ids',
        'domain_tag_ids',
        'source_tag_ids',
        'issue_tag_ids',
        'reference_ids',
        'reference_author_search',
    ];

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
    public function rules(McpEventSearchService $eventSearchService): array
    {
        return $eventSearchService->validationRules();
    }

    #[\Override]
    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (self::ARRAY_FILTER_KEYS as $key) {
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
