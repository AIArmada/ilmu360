<?php

declare(strict_types=1);

namespace App\Platform\Api\Admin\Concerns;

use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Builds the generic workflow schema and workflow action descriptors that
 * record action contributors return for schema-backed record flows.
 */
trait BuildsWorkflowActionDescriptors
{
    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function workflowSchemaActionDescriptor(string $key, string $label, string $description, string $tool, string $recordKey, array $schema): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'category' => 'workflow_schema',
            'description' => $description,
            'tool' => $tool,
            'arguments' => [
                'record_key' => $recordKey,
            ],
            'schema' => $this->normalizedSchemaPayload($schema),
        ];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>|null
     */
    private function workflowActionDescriptor(string $key, string $label, string $description, string $tool, string $recordKey, string $schemaTool, string $schemaActionKey, array $schema): ?array
    {
        $normalizedSchema = $this->normalizedSchemaPayload($schema);
        $defaults = $normalizedSchema['defaults'];
        $availableActions = $normalizedSchema['available_actions'];

        if ($availableActions === []) {
            return null;
        }

        return [
            'key' => $key,
            'label' => $label,
            'category' => 'workflow',
            'description' => $description,
            'tool' => $tool,
            'arguments' => array_merge(['record_key' => $recordKey], $defaults),
            'requires' => [$schemaActionKey],
            'schema_tool' => $schemaTool,
            'schema_tool_arguments' => [
                'record_key' => $recordKey,
            ],
            'schema_action_key' => $schemaActionKey,
            'schema' => [
                ...$normalizedSchema,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array{defaults: array<string, mixed>, fields: list<array<string, mixed>>, conditional_rules: list<array<string, mixed>>, available_actions: list<array<string, mixed>>}
     */
    private function normalizedSchemaPayload(array $schema): array
    {
        return [
            'defaults' => is_array($schema['defaults'] ?? null) ? $schema['defaults'] : [],
            'fields' => array_values(array_filter(
                is_array($schema['fields'] ?? null) ? $schema['fields'] : [],
                is_array(...),
            )),
            'conditional_rules' => array_values(array_filter(
                is_array($schema['conditional_rules'] ?? null) ? $schema['conditional_rules'] : [],
                is_array(...),
            )),
            'available_actions' => $this->extractAvailableActions($schema),
        ];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return list<array<string, mixed>>
     */
    private function extractAvailableActions(array $schema): array
    {
        $availableActions = array_values(array_filter(
            is_array($schema['available_actions'] ?? null) ? $schema['available_actions'] : [],
            is_array(...),
        ));

        if ($availableActions !== []) {
            return $availableActions;
        }

        $fields = is_array($schema['fields'] ?? null) ? $schema['fields'] : [];

        foreach ($fields as $field) {
            if (! is_array($field) || ($field['name'] ?? null) !== 'action') {
                continue;
            }

            $allowedValues = array_values(array_filter(
                is_array($field['allowed_values'] ?? null) ? $field['allowed_values'] : [],
                static fn (mixed $value): bool => is_string($value) && $value !== '',
            ));

            return array_map(
                static fn (string $value): array => [
                    'key' => $value,
                    'label' => Str::headline($value),
                ],
                $allowedValues,
            );
        }

        return [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function trySchema(callable $resolver): ?array
    {
        try {
            $response = $resolver();
        } catch (HttpExceptionInterface) {
            return null;
        }

        $schema = data_get($response, 'data.schema');

        return is_array($schema) ? $schema : null;
    }
}
