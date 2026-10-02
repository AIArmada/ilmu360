<?php

declare(strict_types=1);

namespace App\Actions\Events;

use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventPrayerTime;
use App\Enums\EventVisibility;
use BackedEnum;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Structural boundary guard for frontend event submissions.
 *
 * Rejects malformed shapes, invalid explicit enums, non-UUID IDs, and bad
 * booleans before any lossy normalization, scoped coercion, captcha spend,
 * or write. Defaults apply only when the input key is truly omitted.
 *
 * Entity existence, access, country, timing, and captcha checks stay in the
 * submission orchestrator and its policies; this action only validates
 * structure and returns the normalized state (backed enums as values,
 * collections as arrays, omitted-key defaults).
 */
final readonly class ValidateEventSubmissionInputAction
{
    /**
     * Flat list fields whose item failures are reported at the whole-field
     * key, matching the established form/API error contract.
     *
     * @var list<string>
     */
    private const FLAT_LIST_FIELDS = [
        'age_group',
        'event_category_ids',
        'space_ids',
        'languages',
        'persons',
        'references',
        'domain_tags',
        'discipline_tags',
        'source_tags',
        'issue_tags',
    ];

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function handle(array $state, string $validationKeyPrefix = ''): array
    {
        try {
            Validator::make($this->validationInput($state), $this->rules(), $this->messages())->validate();
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages($this->prefixedErrors(
                $this->collapseFlatListItemErrors($exception->errors()),
                $validationKeyPrefix,
            ));
        }

        return $this->normalizedState($state);
    }

    /**
     * Validation runs on a sanitized copy: backed enums become their values,
     * collections become arrays, UUID inputs are trimmed, and blank strings
     * on blank-tolerant optional fields become null. The original input is
     * never mutated here, so malformed values still reach the rules and fail
     * instead of being silently dropped or coerced.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function validationInput(array $state): array
    {
        $input = $state;

        foreach (['event_format', 'visibility', 'gender', 'prayer_time'] as $field) {
            if (array_key_exists($field, $input)) {
                $input[$field] = $this->nullIfBlank($this->scalarValue($input[$field]));
            }
        }

        if (array_key_exists('location_type', $input)) {
            $input['location_type'] = $this->nullIfBlank($input['location_type']);
        }

        $input = $this->sanitizedUuidInputs($input);

        if (array_key_exists('age_group', $input)) {
            $input['age_group'] = $this->validationList($input['age_group'], fn (mixed $item): mixed => $this->scalarValue($item));
        }

        foreach (['domain_tags', 'discipline_tags', 'source_tags', 'issue_tags'] as $field) {
            if (array_key_exists($field, $input)) {
                $input[$field] = $this->validationList($input[$field], fn (mixed $item): mixed => $this->scalarValue($item));
            }
        }

        return $input;
    }

    /**
     * Shared UUID sanitization (trim, blank-to-null, collections to arrays)
     * used by both the validation copy and the returned normalized state, so
     * valid whitespace-padded IDs validate and persist identically instead of
     * being rejected or dropped downstream.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function sanitizedUuidInputs(array $input): array
    {
        foreach (['primary_organizer_id', 'location_institution_id', 'location_venue_id', 'space_id', 'event_occurrence_id'] as $field) {
            if (array_key_exists($field, $input)) {
                $input[$field] = $this->nullIfBlank($this->trimmedString($input[$field]));
            }
        }

        if (array_key_exists('event_category_ids', $input)) {
            $input['event_category_ids'] = $this->validationList($input['event_category_ids'], fn (mixed $item): mixed => $this->trimmedString($item));
        }

        foreach (['space_ids', 'languages', 'persons', 'references'] as $field) {
            if (array_key_exists($field, $input)) {
                $input[$field] = $this->validationList($input[$field], fn (mixed $item): mixed => $this->nullIfBlank($this->trimmedString($item)));
            }
        }

        if (array_key_exists('other_key_people', $input) && is_array($input['other_key_people'])) {
            $input['other_key_people'] = array_map(function (mixed $row): mixed {
                if (! is_array($row) || ! array_key_exists('involveable_id', $row)) {
                    return $row;
                }

                $row['involveable_id'] = $this->nullIfBlank($this->trimmedString($row['involveable_id']));

                return $row;
            }, $input['other_key_people']);
        }

        return $input;
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'event_format' => ['nullable', Rule::enum(EventFormat::class)],
            'visibility' => ['nullable', Rule::enum(EventVisibility::class)],
            'gender' => ['nullable', Rule::enum(EventGenderRestriction::class)],
            'prayer_time' => ['nullable', Rule::enum(EventPrayerTime::class)],
            'age_group' => ['nullable', 'array', 'min:1'],
            'age_group.*' => ['required', Rule::enum(EventAgeGroup::class)],
            'event_category_ids' => ['required', 'array', 'min:1'],
            'event_category_ids.*' => ['required', 'uuid'],
            'primary_organizer_id' => ['nullable', 'uuid'],
            'location_institution_id' => ['nullable', 'uuid'],
            'location_venue_id' => ['nullable', 'uuid'],
            'space_id' => ['nullable', 'uuid'],
            'event_occurrence_id' => ['nullable', 'uuid'],
            'scoped_institution_id' => ['nullable', 'uuid'],
            'captcha_token' => ['nullable', 'string'],
            'space_ids' => ['nullable', 'array'],
            'space_ids.*' => ['nullable', 'uuid'],
            'languages' => ['nullable', 'array'],
            'languages.*' => ['nullable', 'uuid'],
            'persons' => ['nullable', 'array'],
            'persons.*' => ['nullable', 'uuid'],
            'references' => ['nullable', 'array'],
            'references.*' => ['nullable', 'uuid'],
            'domain_tags' => ['nullable', 'array'],
            'domain_tags.*' => ['required', 'string', 'min:1', 'max:255'],
            'discipline_tags' => ['nullable', 'array'],
            'discipline_tags.*' => ['required', 'string', 'min:1', 'max:255'],
            'source_tags' => ['nullable', 'array'],
            'source_tags.*' => ['required', 'string', 'min:1', 'max:255'],
            'issue_tags' => ['nullable', 'array'],
            'issue_tags.*' => ['required', 'string', 'min:1', 'max:255'],
            'other_key_people' => ['nullable', 'array'],
            'other_key_people.*' => ['array'],
            'other_key_people.*.role_code' => ['required', 'string', Rule::in(array_keys(EventKeyPersonRole::nonSpeakerOptions()))],
            'other_key_people.*.involveable_id' => ['nullable', 'uuid'],
            'other_key_people.*.display_name' => ['nullable', 'required_without:other_key_people.*.involveable_id', 'string', 'max:255'],
            'other_key_people.*.involveable_type' => ['nullable', 'string', 'max:255'],
            'other_key_people.*.visibility' => ['nullable', Rule::in(['public', 'private'])],
            'other_key_people.*.notes' => ['nullable', 'string', 'max:1000'],
            'children_allowed' => ['nullable', 'boolean'],
            'is_muslim_only' => ['nullable', 'boolean'],
            'location_same_as_institution' => ['nullable', 'boolean'],
            'location_type' => ['nullable', Rule::in(['institution', 'venue'])],
            'event_url' => ['nullable', 'string', 'url:http,https', 'max:255'],
            'live_url' => ['nullable', 'string', 'url:http,https', 'max:255'],
            'submitter_name' => ['nullable', 'string', 'max:255'],
            'submitter_email' => ['nullable', 'string', 'max:255'],
            'submitter_phone' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'description' => ['nullable', $this->descriptionRule()],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        $messages = [
            'title.required' => __('Sila masukkan tajuk majlis.'),
            'title.string' => __('Sila masukkan tajuk majlis.'),
            'title.max' => __('Tajuk majlis tidak boleh melebihi 255 aksara.'),
            'event_format.enum' => __('Pilihan tidak sah.'),
            'visibility.enum' => __('Pilihan tidak sah.'),
            'gender.enum' => __('Pilihan tidak sah.'),
            'prayer_time.enum' => __('Sila pilih waktu majlis yang sah.'),
            'age_group.array' => __('Sila pilih kumpulan umur.'),
            'age_group.min' => __('Sila pilih kumpulan umur.'),
            'age_group.*.required' => __('Pilihan kumpulan umur tidak sah.'),
            'age_group.*.enum' => __('Pilihan kumpulan umur tidak sah.'),
            'event_category_ids.required' => __('Sila pilih kategori majlis.'),
            'event_category_ids.array' => __('Sila pilih kategori majlis.'),
            'event_category_ids.min' => __('Sila pilih kategori majlis.'),
            'event_category_ids.*.required' => __('Pilihan kategori majlis tidak sah.'),
            'event_category_ids.*.uuid' => __('Pilihan kategori majlis tidak sah.'),
            'other_key_people.array' => __('Format orang penting tidak sah.'),
            'other_key_people.*.array' => __('Baris orang penting tidak sah.'),
            'other_key_people.*.role_code.required' => __('Kod peranan tidak sah.'),
            'other_key_people.*.role_code.string' => __('Kod peranan tidak sah.'),
            'other_key_people.*.role_code.in' => __('Kod peranan tidak sah.'),
            'other_key_people.*.involveable_id.uuid' => __('Pilihan penceramah tidak sah.'),
            'other_key_people.*.display_name.string' => __('Nilai tidak sah.'),
            'other_key_people.*.display_name.max' => __('Nilai tidak sah.'),
            'other_key_people.*.involveable_type.string' => __('Nilai tidak sah.'),
            'other_key_people.*.involveable_type.max' => __('Nilai tidak sah.'),
            'other_key_people.*.visibility.in' => __('Keterlihatan tidak sah.'),
            'other_key_people.*.notes.string' => __('Nota tidak boleh melebihi 1000 aksara.'),
            'other_key_people.*.notes.max' => __('Nota tidak boleh melebihi 1000 aksara.'),
            'children_allowed.boolean' => __('Nilai tidak sah.'),
            'is_muslim_only.boolean' => __('Nilai tidak sah.'),
            'location_same_as_institution.boolean' => __('Nilai tidak sah.'),
            'location_type.in' => __('Sila pilih jenis lokasi untuk majlis ini.'),
        ];

        foreach (['primary_organizer_id', 'location_institution_id', 'location_venue_id', 'space_id', 'event_occurrence_id'] as $field) {
            $messages["{$field}.uuid"] = __('Pilihan tidak sah.');
        }

        foreach (['space_ids', 'languages', 'persons', 'references'] as $field) {
            $messages["{$field}.array"] = __('Format pilihan tidak sah.');
            $messages["{$field}.*.uuid"] = __('Pilihan mengandungi nilai tidak sah.');
        }

        foreach (['domain_tags', 'discipline_tags', 'source_tags', 'issue_tags'] as $field) {
            $messages["{$field}.array"] = __('Format tag tidak sah.');
            $messages["{$field}.*.required"] = __('Tag mengandungi nilai tidak sah.');
            $messages["{$field}.*.string"] = __('Tag mengandungi nilai tidak sah.');
            $messages["{$field}.*.min"] = __('Tag mengandungi nilai tidak sah.');
            $messages["{$field}.*.max"] = __('Tag mengandungi nilai tidak sah.');
        }

        foreach (['event_url' => 255, 'live_url' => 255, 'submitter_name' => 255, 'submitter_email' => 255, 'submitter_phone' => 20, 'notes' => 1000] as $field => $max) {
            $messages["{$field}.string"] = __('Nilai tidak sah.');
            $messages["{$field}.max"] = __('Nilai tidak boleh melebihi :max aksara.', ['max' => $max]);
        }

        return $messages;
    }

    /**
     * Description accepts a string or the canonical localized array (locale
     * keys mapping to string/null values); arbitrary arrays are rejected so
     * the persister never receives an unpersistable shape. No built-in rule
     * expresses this union, so it stays a small closure.
     */
    private function descriptionRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === null || is_string($value)) {
                return;
            }

            if (! is_array($value)) {
                $fail(__('Huraian majlis tidak sah.'));

                return;
            }

            foreach ($value as $locale => $text) {
                if (! is_string($locale) || trim($locale) === '' || (! is_string($text) && $text !== null)) {
                    $fail(__('Huraian majlis tidak sah.'));

                    return;
                }
            }
        };
    }

    /**
     * Internal arbitrary-taxonomy selections are excluded from this public
     * boundary; only the exposed category and named taxonomy fields persist.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function normalizedState(array $state): array
    {
        $normalized = $this->sanitizedUuidInputs($state);

        unset($normalized['taxonomy_term_ids']);

        $normalized['event_format'] = $this->enumValue($state['event_format'] ?? null, EventFormat::Physical->value);
        $normalized['visibility'] = $this->enumValue($state['visibility'] ?? null, EventVisibility::Public->value);
        $normalized['gender'] = $this->enumValue($state['gender'] ?? null, EventGenderRestriction::All->value);
        $normalized['prayer_time'] = $this->enumValue($state['prayer_time'] ?? null, '');

        if (! array_key_exists('age_group', $normalized) || $normalized['age_group'] === null) {
            $normalized['age_group'] = [EventAgeGroup::AllAges->value];
        } else {
            $normalized['age_group'] = $this->enumList($normalized['age_group']);
        }

        foreach (['domain_tags', 'discipline_tags', 'source_tags', 'issue_tags'] as $field) {
            if (array_key_exists($field, $normalized)) {
                $normalized[$field] = $this->validationList($normalized[$field], fn (mixed $item): mixed => $this->scalarValue($item));
            }
        }

        return $normalized;
    }

    /**
     * Collapse single-level item errors (for example `age_group.0`) back to
     * the whole-field key. Nested `other_key_people` paths keep their indexes.
     * Identical item messages collapse to one entry per field.
     *
     * @param  array<string, list<string>>  $errors
     * @return array<string, list<string>>
     */
    private function collapseFlatListItemErrors(array $errors): array
    {
        $collapsed = [];

        foreach ($errors as $field => $messages) {
            $segments = explode('.', $field);

            if (count($segments) === 2
                && in_array($segments[0], self::FLAT_LIST_FIELDS, true)
                && ctype_digit($segments[1])
            ) {
                $field = $segments[0];
            }

            foreach ((array) $messages as $message) {
                if (! in_array($message, $collapsed[$field] ?? [], true)) {
                    $collapsed[$field][] = $message;
                }
            }
        }

        return $collapsed;
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @return array<string, list<string>>
     */
    private function prefixedErrors(array $errors, string $validationKeyPrefix): array
    {
        if ($validationKeyPrefix === '') {
            return $errors;
        }

        $prefixed = [];

        foreach ($errors as $field => $messages) {
            $prefixed[$validationKeyPrefix.$field] = $messages;
        }

        return $prefixed;
    }

    private function scalarValue(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? (string) $value->value : $value;
    }

    private function trimmedString(mixed $value): mixed
    {
        return is_string($value) ? trim($value) : $value;
    }

    private function nullIfBlank(mixed $value): mixed
    {
        return is_string($value) && trim($value) === '' ? null : $value;
    }

    private function validationList(mixed $value, callable $mapItem): mixed
    {
        if ($value instanceof Collection) {
            $value = $value->all();
        }

        if (! is_array($value)) {
            return $value;
        }

        return array_map($mapItem, $value);
    }

    private function enumValue(mixed $value, string $default = ''): string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if (is_string($value)) {
            return trim($value) === '' ? $default : $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        return $default;
    }

    /**
     * @return list<string>
     */
    private function enumList(mixed $values): array
    {
        if ($values instanceof Collection) {
            $values = $values->all();
        }

        if (! is_array($values)) {
            $values = [$values];
        }

        return collect($values)
            ->map(fn (mixed $value): string => $this->enumValue($value))
            ->filter(fn (string $value): bool => $value !== '')
            ->values()
            ->all();
    }
}
