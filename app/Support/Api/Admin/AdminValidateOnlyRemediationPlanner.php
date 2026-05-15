<?php

declare(strict_types=1);

namespace App\Support\Api\Admin;

use Illuminate\Support\Arr;

final class AdminValidateOnlyRemediationPlanner
{
    private const string ACTION_SET_FIELD = 'set_field';

    private const string ACTION_CHOOSE_ONE = 'choose_one';

    private const string ACTION_CHOOSE_MANY = 'choose_many';

    private const string BLOCKER_REQUIRED_CHOICE = 'required_choice';

    private const string BLOCKER_INVALID_CHOICE = 'invalid_choice';

    private const string BLOCKER_MISSING_VALUE = 'missing_value';

    private const string BLOCKER_VALIDATION_ERROR = 'validation_error';

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $schemaResponse
     * @param  array<string, mixed>  $errors
     * @return array{
     *     fix_plan: list<array<string, mixed>>,
     *     remaining_blockers: list<array<string, mixed>>,
     *     normalized_payload_preview: array<string, mixed>,
     *     can_retry: bool
     * }
     */
    public function build(array $payload, array $schemaResponse, array $errors): array
    {
        $schema = Arr::get($schemaResponse, 'data.schema', []);
        $fieldMap = $this->fieldMapFromSchemaResponse($schemaResponse);
        $defaults = is_array($schema['defaults'] ?? null) ? $schema['defaults'] : [];
        $normalizedPayloadPreview = $this->normalizePreviewPayload($payload);

        $fixPlan = [];
        $remainingBlockers = [];

        foreach ($errors as $field => $messages) {
            if (! is_string($field) || ! is_array($messages)) {
                continue;
            }

            $fieldDefinition = $fieldMap[$field] ?? null;
            $currentValue = data_get($normalizedPayloadPreview, $field);
            $defaultValue = $this->defaultValueForPath($field, $defaults, $fieldDefinition);

            if ($defaultValue['exists'] && $this->isMissingRemediationValue($currentValue)) {
                data_set($normalizedPayloadPreview, $field, $defaultValue['value']);

                $fixPlan[$field] = [
                    'action' => self::ACTION_SET_FIELD,
                    'field' => $field,
                    'value' => $defaultValue['value'],
                    'auto_apply_safe' => true,
                ];

                continue;
            }

            $allowedValues = $this->allowedValuesForField($fieldDefinition);

            if ($allowedValues !== []) {
                $fixPlan[$field] = [
                    'action' => $this->allowsMultipleChoices($fieldDefinition)
                        ? self::ACTION_CHOOSE_MANY
                        : self::ACTION_CHOOSE_ONE,
                    'field' => $field,
                    'options' => $allowedValues,
                    'auto_apply_safe' => false,
                ];

                $remainingBlockers[] = [
                    'field' => $field,
                    'type' => $this->determineBlockerType($currentValue, true),
                    'options' => $allowedValues,
                    'messages' => array_values(array_map(strval(...), $messages)),
                ];

                continue;
            }

            $remainingBlockers[] = [
                'field' => $field,
                'type' => $this->determineBlockerType($currentValue, false),
                'messages' => array_values(array_map(strval(...), $messages)),
            ];
        }

        return [
            'fix_plan' => array_values($fixPlan),
            'remaining_blockers' => $remainingBlockers,
            'normalized_payload_preview' => $normalizedPayloadPreview,
            'can_retry' => $remainingBlockers === [],
        ];
    }

    /**
     * @param  array<string, mixed>  $schemaResponse
     * @return array<string, array<string, mixed>>
     */
    private function fieldMapFromSchemaResponse(array $schemaResponse): array
    {
        $fields = Arr::get($schemaResponse, 'data.schema.fields', []);

        if (! is_array($fields)) {
            return [];
        }

        $fieldMap = [];

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $name = $field['name'] ?? null;

            if (! is_string($name) || $name === '') {
                continue;
            }

            $fieldMap[$name] = $field;
        }

        return $fieldMap;
    }

    /**
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>|null  $fieldDefinition
     * @return array{exists: bool, value: mixed}
     */
    private function defaultValueForPath(string $path, array $defaults, ?array $fieldDefinition): array
    {
        if (Arr::has($defaults, $path)) {
            return [
                'exists' => true,
                'value' => data_get($defaults, $path),
            ];
        }

        if (is_array($fieldDefinition) && array_key_exists('default', $fieldDefinition)) {
            return [
                'exists' => true,
                'value' => $fieldDefinition['default'],
            ];
        }

        return [
            'exists' => false,
            'value' => null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $fieldDefinition
     * @return list<string|int>
     */
    private function allowedValuesForField(?array $fieldDefinition): array
    {
        $allowedValues = $fieldDefinition['allowed_values'] ?? null;

        if (! is_array($allowedValues)) {
            return [];
        }

        // MCP/API write schema option lists are currently exposed as string or integer identifiers,
        // so non-scalar entries are ignored instead of being surfaced as retry suggestions.
        return array_values(array_filter(
            $allowedValues,
            static fn (mixed $value): bool => is_string($value) || is_int($value),
        ));
    }

    /**
     * @param  array<string, mixed>|null  $fieldDefinition
     */
    private function allowsMultipleChoices(?array $fieldDefinition): bool
    {
        return is_string($fieldDefinition['type'] ?? null)
            && str_starts_with($fieldDefinition['type'], 'array<');
    }

    private function isMissingRemediationValue(mixed $value): bool
    {
        return match (true) {
            is_string($value) => trim($value) === '',
            is_array($value) => $value === [],
            default => $value === null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizePreviewPayload(array $payload): array
    {
        $normalizedPayload = [];

        foreach ($payload as $key => $value) {
            // Retry previews are serialized back to JSON, so only standard string/int array keys are retained.
            if (! is_string($key)) {
                continue;
            }

            $normalizedPayload[$key] = $this->normalizePreviewValue($value);
        }

        return $normalizedPayload;
    }

    private function normalizePreviewValue(mixed $value): mixed
    {
        return match (true) {
            is_array($value) => array_map($this->normalizePreviewValue(...), $value),
            is_scalar($value), $value === null => $value,
            $value instanceof \DateTimeInterface => $value->format(DATE_ATOM),
            $value instanceof \Stringable => (string) $value,
            default => null,
        };
    }

    private function determineBlockerType(mixed $currentValue, bool $hasAllowedValues): string
    {
        if ($this->isMissingRemediationValue($currentValue)) {
            return $hasAllowedValues ? self::BLOCKER_REQUIRED_CHOICE : self::BLOCKER_MISSING_VALUE;
        }

        return $hasAllowedValues ? self::BLOCKER_INVALID_CHOICE : self::BLOCKER_VALIDATION_ERROR;
    }
}
