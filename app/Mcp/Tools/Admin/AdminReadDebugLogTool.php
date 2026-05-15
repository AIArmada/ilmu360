<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Admin;

use App\Mcp\Tools\Concerns\ReadsDebugLog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class AdminReadDebugLogTool extends AbstractAdminTool
{
    use ReadsDebugLog;

    protected string $name = 'admin-read-debug-log';

    protected string $title = 'Read Debug Log';

    protected string $description = 'Read recent filtered lines from the application debug log. Defaults to `mcp.tool_execution` so you can inspect MCP tool execution traces. Set `all=true` to return every matching line.';

    public function handle(Request $request): ResponseFactory|Response
    {
        return $this->structuredResponse(function () use ($request): array {
            $this->authorizeAdmin($request);

            $validated = $this->validateArguments($request, [
                'filter' => ['nullable', 'string'],
                'lines' => ['nullable', 'integer', 'min:1', 'max:5000'],
                'all' => ['nullable', 'boolean'],
            ]);

            $filter = array_key_exists('filter', $validated) && is_string($validated['filter'])
                ? $validated['filter']
                : 'mcp.tool_execution';
            $lines = is_int($validated['lines'] ?? null) ? $validated['lines'] : 500;
            $all = (bool) ($validated['all'] ?? false);

            return $this->readFilteredDebugLog($filter, $lines, $all);
        });
    }

    /**
     * @return array<string, Type>
     */
    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return $this->debugLogInputSchema($schema);
    }

    /**
     * @return array<string, Type>
     */
    #[\Override]
    public function outputSchema(JsonSchema $schema): array
    {
        return $this->debugLogOutputSchema($schema);
    }
}
