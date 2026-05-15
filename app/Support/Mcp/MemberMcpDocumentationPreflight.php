<?php

declare(strict_types=1);

namespace App\Support\Mcp;

use Illuminate\Support\Facades\Cache;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Contracts\Transport;
use Laravel\Mcp\Server\Transport\FakeTransporter;

class MemberMcpDocumentationPreflight
{
    public const GUIDE_DOCUMENT_ID = 'docs-member-mcp-guide';

    public const GUIDE_RESOURCE_URI = 'file://docs/ilmu360_mcp_member_agent_guide.md';

    private const string CACHE_KEY_PREFIX = 'mcp:documentation-preflight:member:';

    public function shouldBlockOperationalToolCall(Request $request, string $toolName, ?Transport $transport = null): bool
    {
        if ($this->isDocumentationTool($toolName)) {
            return false;
        }

        if ($this->hasGuideInContext($request)) {
            return false;
        }

        return ! ($transport instanceof FakeTransporter);
    }

    public function markGuideInContext(Request|string|null $request): void
    {
        foreach ($this->contextKeys($request) as $contextKey) {
            Cache::put($this->cacheKey($contextKey), true, now()->addHours(12));
        }
    }

    public function hasGuideInContext(Request|string|null $request): bool
    {
        return array_any($this->contextKeys($request), fn ($contextKey) => Cache::get($this->cacheKey($contextKey), false) === true);
    }

    public function isDocumentationTool(string $toolName): bool
    {
        return in_array($toolName, ['search', 'fetch'], true);
    }

    /**
     * @param  array{id: string, title: string, text: string, url: string, metadata: array<string, mixed>}  $guideDocument
     */
    public function guideInjectionResponse(string $toolName, array $guideDocument): ResponseFactory
    {
        $notice = sprintf(
            '[Guide auto-loaded] The member MCP guide has been loaded and the preflight is now satisfied. Re-invoke [%s] to continue.',
            $toolName,
        );

        return Response::make(Response::text($notice."\n\n".$guideDocument['text']))
            ->withStructuredContent([
                'action' => 'documentation_preflight_injected',
                'notice' => $notice,
                'document' => $guideDocument,
                'retry' => [
                    'tool_name' => $toolName,
                    'instructions' => 'Read the guide above, then re-invoke the requested tool.',
                ],
            ]);
    }

    private function normalizeSessionId(?string $sessionId): ?string
    {
        $normalizedSessionId = is_string($sessionId) ? trim($sessionId) : '';

        return $normalizedSessionId !== '' ? $normalizedSessionId : null;
    }

    /**
     * @return list<string>
     */
    private function contextKeys(Request|string|null $request): array
    {
        if ($request instanceof Request) {
            return $this->contextKeysFromRequest($request);
        }

        $normalizedSessionId = $this->normalizeSessionId($request);

        return $normalizedSessionId === null ? [] : [$normalizedSessionId];
    }

    /**
     * @return list<string>
     */
    private function contextKeysFromRequest(Request $request): array
    {
        $contextKeys = [$request->sessionId(), ...$this->extractSessionIdsFromMeta($request->meta())];

        return array_values(array_unique(array_filter(
            array_map(fn (mixed $contextKey): ?string => is_string($contextKey) ? $this->normalizeSessionId($contextKey) : null, $contextKeys),
            fn (?string $contextKey): bool => $contextKey !== null,
        )));
    }

    /**
     * @param  array<string, mixed>|null  $meta
     * @param  list<string>  $path
     * @return list<string>
     */
    private function extractSessionIdsFromMeta(?array $meta, array $path = []): array
    {
        if ($meta === null) {
            return [];
        }

        $sessionIds = [];

        foreach ($meta as $key => $value) {
            $currentPath = [...$path, (string) $key];

            if (is_array($value)) {
                $sessionIds = [...$sessionIds, ...$this->extractSessionIdsFromMeta($value, $currentPath)];

                continue;
            }

            if (is_string($value) && $this->pathLooksLikeSessionId($currentPath)) {
                $sessionIds[] = $value;
            }
        }

        return $sessionIds;
    }

    /**
     * @param  list<string>  $path
     */
    private function pathLooksLikeSessionId(array $path): bool
    {
        $normalizedPath = array_values(array_filter(array_map(
            $this->normalizeMetaSegment(...),
            $path,
        )));

        $lastSegment = $normalizedPath[array_key_last($normalizedPath)] ?? null;

        if (in_array($lastSegment, ['sessionid', 'mcpsessionid'], true)) {
            return true;
        }

        $previousSegment = count($normalizedPath) >= 2
            ? $normalizedPath[count($normalizedPath) - 2]
            : null;

        return $lastSegment === 'id' && in_array($previousSegment, ['session', 'mcpsession'], true);
    }

    private function normalizeMetaSegment(string $segment): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower($segment)) ?? '';
    }

    private function cacheKey(string $sessionId): string
    {
        return self::CACHE_KEY_PREFIX.$sessionId;
    }
}
