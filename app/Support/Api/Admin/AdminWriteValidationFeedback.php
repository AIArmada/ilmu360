<?php

declare(strict_types=1);

namespace App\Support\Api\Admin;

use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

final class AdminWriteValidationFeedback
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $schemaResponse
     * @return array<string, mixed>
     */
    public function payloadWithSchemaDefaults(array $payload, array $schemaResponse): array
    {
        $defaults = Arr::get($schemaResponse, 'data.schema.defaults', []);

        if (! is_array($defaults)) {
            return $payload;
        }

        return $this->mergeMissingValues($defaults, $payload);
    }

    public function message(ValidationException $exception): string
    {
        $firstMessage = Arr::first(Arr::flatten($exception->errors()));

        if (is_string($firstMessage) && trim($firstMessage) !== '') {
            return $firstMessage;
        }

        return trim($exception->getMessage()) !== ''
            ? $exception->getMessage()
            : 'The given data was invalid.';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $schemaResponse
     * @param  array<string, mixed>|null  $candidatePayload
     * @return array<string, mixed>
     */
    public function feedback(
        ValidationException $exception,
        array $payload,
        array $schemaResponse,
        string $operation,
        bool $validateOnly,
        bool $applyDefaults,
        ?array $candidatePayload = null,
    ): array {
        $schema = Arr::get($schemaResponse, 'data.schema', []);
        $fieldContracts = $this->fieldContracts($schema);
        $failedRules = $exception->validator !== null
            ? array_map(
                static fn (array $rules): array => array_values(array_map(strtolower(...), array_keys($rules))),
                $exception->validator->failed(),
            )
            : [];

        $issues = [];

        foreach ($exception->errors() as $field => $messages) {
            $contract = $this->fieldContract($field, $fieldContracts);
            $allowedValues = is_array($contract['allowed_values'] ?? null) ? array_values($contract['allowed_values']) : null;
            $currentValue = $this->fieldValue($payload, $field);
            $missing = ! $this->fieldExists($payload, $field) || $currentValue === null || $currentValue === '';
            $default = $contract['default'] ?? null;
            $closestValidValue = $this->closestValidValue($currentValue, $allowedValues);
            $firstAllowedValue = $this->firstAllowedValue($allowedValues);
            $suggested = $this->suggestedValue($missing, $default, $closestValidValue, $firstAllowedValue);
            $requiredBecause = $this->requiredBecause($field, $schema, $candidatePayload ?? $payload);
            $autoFillSafe = $missing && array_key_exists('default', $contract);

            $issue = [
                'field' => $field,
                'messages' => $messages,
                'rule_codes' => $failedRules[$field] ?? [],
                'severity' => $autoFillSafe ? 'auto_fixable' : 'blocking_error',
                'missing' => $missing,
                'auto_fill_safe' => $autoFillSafe,
            ];

            if ($this->fieldExists($payload, $field)) {
                $issue['current_value'] = $currentValue;
            }

            if ($allowedValues !== null) {
                $issue['allowed_values'] = $allowedValues;
            }

            if ($closestValidValue !== null) {
                $issue['closest_valid_value'] = $closestValidValue;
            }

            if ($suggested !== null) {
                $issue['suggested'] = $suggested;
            }

            if ($default !== null) {
                $issue['default'] = $default;
                $issue['example_value'] = $default;
            } elseif ($firstAllowedValue !== null) {
                $issue['example_value'] = $firstAllowedValue;
            }

            if ($requiredBecause !== null) {
                $issue['required_because'] = $requiredBecause;
            }

            $issues[] = $issue;
        }

        $feedback = [
            'operation' => $operation,
            'validate_only' => $validateOnly,
            'apply_defaults' => $applyDefaults,
            'issues' => $issues,
        ];

        if ($candidatePayload !== null) {
            $feedback['normalized_payload'] = $candidatePayload;
        }

        return $feedback;
    }

    /**
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function mergeMissingValues(array $defaults, array $payload): array
    {
        foreach ($defaults as $key => $value) {
            if (! array_key_exists($key, $payload)) {
                $payload[$key] = $value;

                continue;
            }

            if ($this->shouldMergeRecursively($value, $payload[$key])) {
                $payload[$key] = $this->mergeMissingValues($value, $payload[$key]);
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, array<string, mixed>>
     */
    private function fieldContracts(array $schema): array
    {
        $fields = $schema['fields'] ?? [];

        if (! is_array($fields)) {
            return [];
        }

        $contracts = [];

        foreach ($fields as $field) {
            if (! is_array($field) || ! is_string($field['name'] ?? null)) {
                continue;
            }

            $contracts[$field['name']] = $field;
        }

        return $contracts;
    }

    /**
     * @param  array<string, array<string, mixed>>  $fieldContracts
     * @return array<string, mixed>
     */
    private function fieldContract(string $field, array $fieldContracts): array
    {
        if (array_key_exists($field, $fieldContracts)) {
            return $fieldContracts[$field];
        }

        $wildcardField = $this->normalizeFieldPathForWildcard($field);

        if (array_key_exists($wildcardField, $fieldContracts)) {
            return $fieldContracts[$wildcardField];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function fieldExists(array $payload, string $field): bool
    {
        $segments = explode('.', $field);
        $current = $payload;

        foreach ($segments as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return false;
            }

            $current = $current[$segment];
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function fieldValue(array $payload, string $field): mixed
    {
        return data_get($payload, $field);
    }

    /**
     * @param  list<string|int>|null  $allowedValues
     */
    private function closestValidValue(mixed $value, ?array $allowedValues): string|int|null
    {
        if ($allowedValues === null || $allowedValues === []) {
            return null;
        }

        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $normalizedValue = strtolower(trim((string) $value));

        if ($normalizedValue === '') {
            return null;
        }

        $closest = null;
        $shortestDistance = null;

        foreach ($allowedValues as $allowedValue) {
            if (! is_string($allowedValue) && ! is_int($allowedValue)) {
                continue;
            }

            $candidate = strtolower((string) $allowedValue);

            if ($candidate === $normalizedValue) {
                return $allowedValue;
            }

            $distance = levenshtein($normalizedValue, $candidate);

            if ($shortestDistance === null || $distance < $shortestDistance) {
                $closest = $allowedValue;
                $shortestDistance = $distance;
            }
        }

        return $closest;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function requiredBecause(string $field, array $schema, array $payload): ?array
    {
        $conditionalRules = $schema['conditional_rules'] ?? [];

        if (! is_array($conditionalRules)) {
            return null;
        }

        foreach ($conditionalRules as $rule) {
            if (! is_array($rule) || ($rule['field'] ?? null) !== $field) {
                continue;
            }

            if (is_array($rule['required_when'] ?? null)) {
                $matched = [];

                foreach ($rule['required_when'] as $otherField => $expectedValues) {
                    $actualValue = data_get($payload, $otherField);

                    if (is_array($expectedValues) && in_array($actualValue, $expectedValues, true)) {
                        $matched[$otherField] = $actualValue;
                    }
                }

                if ($matched !== []) {
                    return $matched;
                }
            }

            if (is_array($rule['required_unless'] ?? null)) {
                $matched = [];

                foreach ($rule['required_unless'] as $otherField => $expectedValues) {
                    $actualValue = data_get($payload, $otherField);

                    if (is_array($expectedValues) && ! in_array($actualValue, $expectedValues, true)) {
                        $matched[$otherField] = $actualValue;
                    }
                }

                if ($matched !== []) {
                    return $matched;
                }
            }
        }

        return null;
    }

    private function normalizeFieldPathForWildcard(string $field): string
    {
        return preg_replace('/\.\d+(?=\.|$)/', '.*', $field) ?? $field;
    }

    private function suggestedValue(
        bool $missing,
        mixed $default,
        string|int|null $closestValidValue,
        string|int|null $firstAllowedValue,
    ): mixed {
        if ($missing) {
            return $default ?? $firstAllowedValue;
        }

        return $closestValidValue ?? $default ?? $firstAllowedValue;
    }

    /**
     * @param  list<string|int>|null  $allowedValues
     */
    private function firstAllowedValue(?array $allowedValues): string|int|null
    {
        if ($allowedValues === null || $allowedValues === []) {
            return null;
        }

        $firstAllowedValue = reset($allowedValues);

        return is_string($firstAllowedValue) || is_int($firstAllowedValue)
            ? $firstAllowedValue
            : null;
    }

    /**
     * Recursively merge nested object-like payload fragments while treating list values as atomic.
     * Associative arrays such as `address => ['country_id' => 132]` merge with defaults, while sequential
     * arrays such as `event_type => ['kuliah_ceramah']` replace defaults instead of merging item-by-item.
     */
    private function shouldMergeRecursively(mixed $defaultValue, mixed $payloadValue): bool
    {
        return is_array($defaultValue)
            && is_array($payloadValue)
            && ! array_is_list($defaultValue)
            && ! array_is_list($payloadValue);
    }
}
