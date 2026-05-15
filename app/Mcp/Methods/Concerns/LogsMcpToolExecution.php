<?php

declare(strict_types=1);

namespace App\Mcp\Methods\Concerns;

use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Server\Transport\JsonRpcRequest;
use Throwable;

trait LogsMcpToolExecution
{
    protected function logToolExecutionStarted(JsonRpcRequest $request, string $toolName, string $server): void
    {
        $arguments = is_array($request->params['arguments'] ?? null)
            ? $request->params['arguments']
            : [];

        Log::debug('mcp.tool_execution.start', [
            'server' => $server,
            'tool' => $toolName,
            'request_id' => $request->id,
            'argument_keys' => array_values(array_map(strval(...), array_keys($arguments))),
            'arguments' => $this->summarizeArguments($arguments),
        ]);
    }

    protected function logToolExecutionBlocked(JsonRpcRequest $request, string $toolName, string $server, string $reason): void
    {
        Log::debug('mcp.tool_execution.blocked', [
            'server' => $server,
            'tool' => $toolName,
            'request_id' => $request->id,
            'reason' => $reason,
        ]);
    }

    protected function logToolExecutionCompleted(JsonRpcRequest $request, string $toolName, string $server, bool $streaming, float $startedAt): void
    {
        Log::debug('mcp.tool_execution.complete', [
            'server' => $server,
            'tool' => $toolName,
            'request_id' => $request->id,
            'streaming' => $streaming,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
    }

    protected function logToolExecutionFailed(JsonRpcRequest $request, string $toolName, string $server, Throwable $exception, float $startedAt): void
    {
        Log::debug('mcp.tool_execution.failed', [
            'server' => $server,
            'tool' => $toolName,
            'request_id' => $request->id,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function summarizeArguments(array $arguments): array
    {
        $summary = [];

        foreach ($arguments as $key => $value) {
            $keyString = (string) $key;

            if ($this->isSensitiveKey($keyString)) {
                $summary[$keyString] = '[redacted]';

                continue;
            }

            $summary[$keyString] = $this->summarizeValue($value);
        }

        return $summary;
    }

    private function summarizeValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return [
                'type' => 'string',
                'length' => mb_strlen($value),
            ];
        }

        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }

        if (is_array($value)) {
            return [
                'type' => 'array',
                'count' => count($value),
            ];
        }

        return [
            'type' => get_debug_type($value),
        ];
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);

        return array_any(['token', 'password', 'secret', 'authorization', 'cookie'], fn ($needle) => str_contains($normalized, (string) $needle));
    }
}
