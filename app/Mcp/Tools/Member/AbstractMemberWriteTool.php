<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Member;

use App\Models\User;
use App\Support\Api\Member\MemberResourceService;
use App\Support\Mcp\McpAuthenticatedUserResolver;
use App\Support\Mcp\McpFilePayloadNormalizer;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;

abstract class AbstractMemberWriteTool extends AbstractMemberTool
{
    /**
     * @param  array<string, mixed>  $payload
     */
    protected function ensureDestructiveMediaClearFlagsAreUnsupported(array $payload): void
    {
        $errors = [];

        foreach (['clear_logo', 'clear_cover', 'clear_avatar', 'clear_poster', 'clear_front_cover', 'clear_back_cover', 'clear_gallery', 'clear_main', 'clear_qr', 'clear_evidence'] as $field) {
            if (! array_key_exists($field, $payload) || ! $this->hasMeaningfulMediaValue($payload[$field])) {
                continue;
            }

            $errors[$field] = ['Destructive media clear flags are not supported through MCP. Upload a replacement file or array when the schema advertises that media field.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function normalizePayloadForWriteTool(string $resourceKey, array $payload): array
    {
        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $schemaResponse
     * @return array{payload: array<string, mixed>, temporary_paths: list<string>}
     */
    protected function normalizeMcpMediaPayload(array $payload, array $schemaResponse): array
    {
        return app(McpFilePayloadNormalizer::class)->normalize(
            $payload,
            $this->mediaFieldContractsFromSchemaResponse($schemaResponse),
        );
    }

    /**
     * @param  array{temporary_paths?: list<string>}  $normalizedMediaPayload
     */
    protected function cleanupMcpMediaPayload(array $normalizedMediaPayload): void
    {
        app(McpFilePayloadNormalizer::class)->cleanup($normalizedMediaPayload['temporary_paths'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $schemaResponse
     * @return array<string, array<string, mixed>>
     */
    private function mediaFieldContractsFromSchemaResponse(array $schemaResponse): array
    {
        $fields = Arr::get($schemaResponse, 'data.schema.fields', []);

        if (! is_array($fields)) {
            return [];
        }

        $contracts = [];

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            /** @var array<string, mixed> $field */
            $name = $field['name'] ?? null;
            $type = $field['type'] ?? null;

            if (! is_string($name) || ! is_string($type) || ! str_contains($type, 'file')) {
                continue;
            }

            $contracts[$name] = $field;
        }

        return $contracts;
    }

    public function shouldRegister(Request $request, MemberResourceService $resourceService): bool
    {
        $user = app(McpAuthenticatedUserResolver::class)->resolve($request->user());

        return $user instanceof User && $resourceService->hasAnyWritableResourceAccess($user);
    }

    protected function hasMeaningfulMediaValue(mixed $value): bool
    {
        return match (true) {
            is_string($value) => trim($value) !== '',
            is_array($value) => $value !== [],
            is_bool($value) => $value,
            default => $value !== null,
        };
    }
}
