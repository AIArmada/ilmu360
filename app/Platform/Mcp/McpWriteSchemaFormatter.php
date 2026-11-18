<?php

declare(strict_types=1);

namespace App\Platform\Mcp;

final class McpWriteSchemaFormatter
{
    /**
     * @var list<string>
     */
    private const array MEDIA_FIELDS = [
        'logo',
        'cover',
        'avatar',
        'poster',
        'front_cover',
        'back_cover',
        'gallery',
        'main',
        'qr',
        'evidence',
    ];

    /**
     * @var list<string>
     */
    private const array DESTRUCTIVE_MEDIA_CLEAR_FIELDS = [
        'clear_logo',
        'clear_cover',
        'clear_avatar',
        'clear_poster',
        'clear_front_cover',
        'clear_back_cover',
        'clear_gallery',
        'clear_main',
        'clear_qr',
        'clear_evidence',
    ];

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $toolArguments
     * @return array<string, mixed>
     */
    public function formatSchema(array $schema, string $tool, array $toolArguments): array
    {
        $fields = is_array($schema['fields'] ?? null) ? $schema['fields'] : [];
        $mediaFields = $this->mediaFields($fields);

        $schema['transport'] = 'mcp';
        $schema['tool'] = $tool;
        $schema['tool_arguments'] = $toolArguments;
        $schema['apply_defaults_semantics'] = [
            'scope' => 'preview_only',
            'honored_when' => 'validate_only=true and apply_defaults=true',
            'persisted_writes' => 'ignored; send the values you want saved',
            'purpose' => 'schema-default autofill for validation feedback, not a write-time mutation shortcut',
        ];
        $schema['mcp_only_semantics'] = $this->mcpOnlySemantics($tool);
        $schema['endpoint'] = null;
        $schema['content_type'] = 'application/json';
        $schema['media_uploads_supported'] = $mediaFields !== [];
        $schema['media_upload_transport'] = $mediaFields === [] ? null : 'json_file_descriptor';
        $schema['file_descriptor_shape'] = $mediaFields === [] ? null : [
            'filename' => 'Original client filename. Include an extension when available; otherwise mime_type is used for the staged extension.',
            'mime_type' => 'Optional IANA media type, for example image/jpeg or application/pdf.',
            'content_base64' => 'Raw base64 file bytes. Data URLs are also accepted. Provide exactly one content source.',
            'content_url' => 'Absolute http(s) URL to download the file bytes. Provide exactly one content source. Multipart is not supported in MCP tool calls.',
            'download_url' => 'ChatGPT connector file URL. Provide exactly one content source.',
        ];
        $schema['unsupported_fields'] = [];
        $schema['destructive_media_clear_fields_supported'] = false;
        $schema['fields'] = $this->annotateFields($fields);

        return $schema;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mcpOnlySemantics(string $tool): ?array
    {
        if (! in_array($tool, ['admin-create-event', 'admin-update-event'], true)) {
            return null;
        }

        return [
            'wrapper' => 'This tool resolves route-key fields, then calls the shared admin events write path.',
            'route_keys' => [
                'primary_organizer_key' => 'resolves to the primary organizer via event_involvements (role_code=organizer, is_primary=true)',
                'institution_key' => 'resolves to institution_id',
                'venue_key' => 'resolves to venue_id',
                'space_key' => 'resolves to space_id',
                'person_keys' => 'resolves to persons',
                'reference_keys' => 'resolves to references',
            ],
            'update_relation_arrays' => [
                'omitted' => 'preserve existing relationship set',
                'null' => 'preserve existing relationship set',
                'empty_array' => 'detach all related records for that field',
                'non_empty_array' => 'replace the full relationship set with the resolved records',
            ],
        ];
    }

    /**
     * @param  array<int, mixed>  $fields
     * @return list<string>
     */
    private function mediaFields(array $fields): array
    {
        $mediaFields = [];

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $name = $this->fieldName($field);

            if ($name === null || ! $this->isMediaField($field, $name)) {
                continue;
            }

            $mediaFields[] = $name;
        }

        return array_values(array_unique($mediaFields));
    }

    /**
     * @param  array<int, mixed>  $fields
     * @return list<array<string, mixed>>
     */
    private function annotateFields(array $fields): array
    {
        $annotated = [];

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            /** @var array<string, mixed> $field */
            $name = $this->fieldName($field);

            if ($name !== null && in_array($name, self::DESTRUCTIVE_MEDIA_CLEAR_FIELDS, true)) {
                continue;
            }

            if ($name !== null && $this->isMediaField($field, $name)) {
                $field['supported'] = true;
                $field['mcp_upload'] = $this->mediaUploadDescriptor($field);
            }

            $annotated[] = $field;
        }

        return $annotated;
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function fieldName(array $field): ?string
    {
        $name = $field['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function isMediaField(array $field, string $name): bool
    {
        $type = $field['type'] ?? null;

        return in_array($name, self::MEDIA_FIELDS, true)
            || (is_string($type) && str_contains($type, 'file'));
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    private function mediaUploadDescriptor(array $field): array
    {
        $type = $field['type'] ?? null;
        $isMultiple = is_string($type) && str_contains($type, 'array');

        return array_filter([
            'transport' => 'json_file_descriptor',
            'shape' => $isMultiple ? 'array<file_descriptor>' : 'file_descriptor',
            'replacement_semantics' => $isMultiple ? 'submitted_array_replaces_collection' : 'submitted_file_replaces_collection',
            'required_fields' => ['filename'],
            'exactly_one_of' => ['content_base64', 'content_url', 'download_url'],
            'accepted_mime_types' => $field['accepted_mime_types'] ?? null,
            'max_file_size_kb' => $field['max_file_size_kb'] ?? null,
            'max_files' => $field['max_files'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
