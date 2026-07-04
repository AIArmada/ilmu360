<?php

declare(strict_types=1);

namespace App\Support\Documentation;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * @phpstan-type DocumentationRecord array{
 *   id: string,
 *   title: string,
 *   description: string,
 *   relative_path: string,
 *   resource_uri: string,
 *   mime_type: string,
 *   audiences: list<string>,
 *   tags: list<string>
 * }
 * @phpstan-type ApiDocumentationSummary array{
 *   id: string,
 *   title: string,
 *   description: string,
 *   endpoint: string,
 *   relative_path: string,
 *   resource_uri: string,
 *   mime_type: string,
 *   audiences: list<string>,
 *   tags: list<string>
 * }
 */
class DocumentationLibrary
{
    public const AUDIENCE_API = 'api';

    public const AUDIENCE_MCP_ADMIN = 'mcp_admin';

    public const AUDIENCE_MCP_MEMBER = 'mcp_member';

    /**
     * @return list<DocumentationRecord>
     */
    public function all(): array
    {
        return array_values(array_filter([
            [
                'id' => 'docs-api-mcp-filament-crud-comparison',
                'title' => 'ilmu360° API / MCP / Filament CRUD Comparison',
                'description' => 'Capability matrix comparing the public API, admin/member MCP surfaces, and Filament-admin CRUD behavior.',
                'relative_path' => 'docs/ilmu360_api_mcp_filament_crud_comparison.md',
                'resource_uri' => 'file://docs/ilmu360_api_mcp_filament_crud_comparison.md',
                'mime_type' => 'text/markdown',
                'audiences' => [self::AUDIENCE_API, self::AUDIENCE_MCP_ADMIN, self::AUDIENCE_MCP_MEMBER],
                'tags' => ['api', 'mcp', 'filament', 'crud', 'capability-matrix'],
            ],
            [
                'id' => 'docs-api-mcp-filament-crud-comparison-json',
                'title' => 'ilmu360° API / MCP / Filament CRUD Comparison (JSON)',
                'description' => 'Machine-readable companion for the API, MCP, and Filament CRUD comparison matrix.',
                'relative_path' => 'docs/ilmu360_api_mcp_filament_crud_comparison.json',
                'resource_uri' => 'file://docs/ilmu360_api_mcp_filament_crud_comparison.json',
                'mime_type' => 'application/json',
                'audiences' => [self::AUDIENCE_API],
                'tags' => ['api', 'mcp', 'filament', 'crud', 'json'],
            ],
            [
                'id' => 'docs-event-domain-understanding',
                'title' => 'ilmu360° Event Domain Understanding',
                'description' => 'Domain primer for event structure, scheduling, speakers, institutions, and related content semantics.',
                'relative_path' => 'docs/ilmu360_event_domain_understanding.md',
                'resource_uri' => 'file://docs/ilmu360_event_domain_understanding.md',
                'mime_type' => 'text/markdown',
                'audiences' => [self::AUDIENCE_API, self::AUDIENCE_MCP_ADMIN, self::AUDIENCE_MCP_MEMBER],
                'tags' => ['domain', 'events', 'content-model'],
            ],
            [
                'id' => 'docs-admin-mcp-guide',
                'title' => 'ilmu360° Admin MCP Agent Guide',
                'description' => 'Verified guide for admin MCP auth, transport rules, discovery primitives, capability matrix, writable resources, and workflow guidance.',
                'relative_path' => 'docs/ilmu360_mcp_admin_agent_guide.md',
                'resource_uri' => 'file://docs/ilmu360_mcp_admin_agent_guide.md',
                'mime_type' => 'text/markdown',
                'audiences' => [self::AUDIENCE_API, self::AUDIENCE_MCP_ADMIN],
                'tags' => ['mcp', 'admin', 'guide', 'operations'],
            ],
            [
                'id' => 'docs-admin-event-csv-json-create-guide',
                'title' => 'ilmu360° MCP CSV / JSON Event Creation Playbook',
                'description' => 'Verified workflow for creating events from CSV or JSON payloads through admin MCP tools, including correction handling and validate-then-create execution.',
                'relative_path' => 'docs/ilmu360_mcp_event_csv_json_creation_guide.md',
                'resource_uri' => 'file://docs/ilmu360_mcp_event_csv_json_creation_guide.md',
                'mime_type' => 'text/markdown',
                'audiences' => [self::AUDIENCE_API, self::AUDIENCE_MCP_ADMIN, self::AUDIENCE_MCP_MEMBER],
                'tags' => ['mcp', 'admin', 'events', 'csv', 'json', 'batch-create'],
            ],
            [
                'id' => 'docs-general-mcp-guide',
                'title' => 'ilmu360° MCP Guide',
                'description' => 'Cross-surface MCP guide covering connector setup, discovery patterns, tool routing, and API-to-MCP operating rules.',
                'relative_path' => 'docs/ilmu360_mcp_guide.md',
                'resource_uri' => 'file://docs/ilmu360_mcp_guide.md',
                'mime_type' => 'text/markdown',
                'audiences' => [self::AUDIENCE_API, self::AUDIENCE_MCP_ADMIN, self::AUDIENCE_MCP_MEMBER],
                'tags' => ['mcp', 'guide', 'connector', 'routing'],
            ],
            [
                'id' => 'docs-member-mcp-guide',
                'title' => 'ilmu360° Member MCP Agent Guide',
                'description' => 'Verified guide for member MCP auth, transport rules, discovery primitives, capability matrix, writable resources, and workflow guidance.',
                'relative_path' => 'docs/ilmu360_mcp_member_agent_guide.md',
                'resource_uri' => 'file://docs/ilmu360_mcp_member_agent_guide.md',
                'mime_type' => 'text/markdown',
                'audiences' => [self::AUDIENCE_API, self::AUDIENCE_MCP_MEMBER],
                'tags' => ['mcp', 'member', 'guide', 'operations'],
            ],
            [
                'id' => 'docs-mcp-tool-examples',
                'title' => 'ilmu360° MCP Tool Examples',
                'description' => 'Example MCP prompts and tool payloads for common admin and member workflows.',
                'relative_path' => 'docs/ilmu360_mcp_tool_examples.md',
                'resource_uri' => 'file://docs/ilmu360_mcp_tool_examples.md',
                'mime_type' => 'text/markdown',
                'audiences' => [self::AUDIENCE_API, self::AUDIENCE_MCP_ADMIN, self::AUDIENCE_MCP_MEMBER],
                'tags' => ['mcp', 'examples', 'prompts', 'tool-payloads'],
            ],
            [
                'id' => 'docs-mobile-api-reference',
                'title' => 'ilmu360° Mobile API Reference',
                'description' => 'Canonical HTTP API reference for public, authenticated, and admin client integrations.',
                'relative_path' => 'docs/ilmu360_mobile_api_reference.md',
                'resource_uri' => 'file://docs/ilmu360_mobile_api_reference.md',
                'mime_type' => 'text/markdown',
                'audiences' => [self::AUDIENCE_API, self::AUDIENCE_MCP_ADMIN, self::AUDIENCE_MCP_MEMBER],
                'tags' => ['api', 'mobile', 'reference', 'http'],
            ],
            [
                'id' => 'docs-mvp-status',
                'title' => 'ilmu360° MVP Status',
                'description' => 'Project status snapshot for the current MVP scope and completion state.',
                'relative_path' => 'docs/ilmu360_mvp_status.md',
                'resource_uri' => 'file://docs/ilmu360_mvp_status.md',
                'mime_type' => 'text/markdown',
                'audiences' => [self::AUDIENCE_API],
                'tags' => ['status', 'mvp', 'planning'],
            ],
            [
                'id' => 'docs-review-and-enhancement-plan',
                'title' => 'ilmu360° Review And Enhancement Plan',
                'description' => 'Active review findings and enhancement plan for the application.',
                'relative_path' => 'docs/ilmu360_review_and_enhancement_plan.md',
                'resource_uri' => 'file://docs/ilmu360_review_and_enhancement_plan.md',
                'mime_type' => 'text/markdown',
                'audiences' => [self::AUDIENCE_API],
                'tags' => ['review', 'enhancement-plan', 'planning'],
            ],
            [
                'id' => 'docs-technical-documentation',
                'title' => 'ilmu360° Developer Technical Documentation',
                'description' => 'Developer-oriented technical reference covering architecture, stack, repository layout, and domain implementation notes.',
                'relative_path' => 'docs/ilmu360_technical_documentation.md',
                'resource_uri' => 'file://docs/ilmu360_technical_documentation.md',
                'mime_type' => 'text/markdown',
                'audiences' => [self::AUDIENCE_API, self::AUDIENCE_MCP_ADMIN, self::AUDIENCE_MCP_MEMBER],
                'tags' => ['technical', 'architecture', 'developer', 'reference'],
            ],
            [
                'id' => 'docs-visitor-guide',
                'title' => 'ilmu360° Visitor Guide',
                'description' => 'Visitor-facing guide to the public experience, discovery paths, and content surfaces.',
                'relative_path' => 'docs/ilmu360_visitor_guide.md',
                'resource_uri' => 'file://docs/ilmu360_visitor_guide.md',
                'mime_type' => 'text/markdown',
                'audiences' => [self::AUDIENCE_API],
                'tags' => ['visitor', 'guide', 'public-experience'],
            ],
        ], fn (array $document): bool => is_file(base_path($document['relative_path']))));
    }

    public function apiCatalogEndpoint(): string
    {
        return route('api.client.documentation.index');
    }

    public function apiDocumentEndpointTemplate(): string
    {
        return route('api.client.documentation.show', ['documentId' => 'documentId'], false);
    }

    /**
     * @return list<ApiDocumentationSummary>
     */
    public function apiLibrary(): array
    {
        return array_values(array_map(
            fn (array $document): array => [
                'id' => $document['id'],
                'title' => $document['title'],
                'description' => $document['description'],
                'endpoint' => route('api.client.documentation.show', ['documentId' => $document['id']]),
                'relative_path' => $document['relative_path'],
                'resource_uri' => $document['resource_uri'],
                'mime_type' => $document['mime_type'],
                'audiences' => $document['audiences'],
                'tags' => $document['tags'],
            ],
            $this->forAudience(self::AUDIENCE_API),
        ));
    }

    /**
     * @return array{
     *   id: string,
     *   title: string,
     *   description: string,
     *   text: string,
     *   relative_path: string,
     *   resource_uri: string,
     *   mime_type: string,
     *   audiences: list<string>,
     *   tags: list<string>,
     *   last_modified: string
     * }|null
     */
    public function apiDocument(string $id): ?array
    {
        $document = $this->findForAudience(self::AUDIENCE_API, $id);

        if ($document === null) {
            return null;
        }

        $contents = $this->readContents($document);

        if ($contents === null) {
            return null;
        }

        return [
            'id' => $document['id'],
            'title' => $document['title'],
            'description' => $document['description'],
            'text' => $contents,
            'relative_path' => $document['relative_path'],
            'resource_uri' => $document['resource_uri'],
            'mime_type' => $document['mime_type'],
            'audiences' => $document['audiences'],
            'tags' => $document['tags'],
            'last_modified' => CarbonImmutable::createFromTimestampUTC(filemtime(base_path($document['relative_path'])) ?: time())->toIso8601String(),
        ];
    }

    /**
     * @return list<DocumentationRecord>
     */
    public function forAudience(string $audience): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (array $document): bool => in_array($audience, $document['audiences'], true),
        ));
    }

    public function hasAnyDocumentsForAudience(string $audience): bool
    {
        return $this->forAudience($audience) !== [];
    }

    /**
     * @return DocumentationRecord|null
     */
    public function findForAudience(string $audience, string $id): ?array
    {
        foreach ($this->forAudience($audience) as $document) {
            if ($document['id'] === $id) {
                return $document;
            }
        }

        return null;
    }

    /**
     * @return array{results: list<array{id: string, title: string, url: string}>}
     */
    public function searchForAudience(string $audience, string $query): array
    {
        $normalizedQuery = Str::lower(trim($query));

        $results = collect($this->forAudience($audience))
            ->map(function (array $document) use ($normalizedQuery): ?array {
                $contents = $this->readContents($document);

                if ($contents === null) {
                    return null;
                }

                $score = $this->score($normalizedQuery, $document, $contents);

                if ($score <= 0) {
                    return null;
                }

                return [
                    'score' => $score,
                    'result' => [
                        'id' => $document['id'],
                        'title' => $document['title'],
                        'url' => $document['resource_uri'],
                    ],
                ];
            })
            ->filter()
            ->sortByDesc('score')
            ->pluck('result')
            ->values()
            ->all();

        return [
            'results' => $results,
        ];
    }

    /**
     * @return array{id: string, title: string, text: string, url: string, metadata: array<string, string>}|null
     */
    public function fetchForAudience(string $audience, string $id): ?array
    {
        $document = $this->findForAudience($audience, $id);

        if ($document === null) {
            return null;
        }

        $contents = $this->readContents($document);

        if ($contents === null) {
            return null;
        }

        return [
            'id' => $document['id'],
            'title' => $document['title'],
            'text' => $contents,
            'url' => $document['resource_uri'],
            'metadata' => [
                'description' => $document['description'],
                'mime_type' => $document['mime_type'],
                'resource_uri' => $document['resource_uri'],
                'relative_path' => $document['relative_path'],
                'last_modified' => CarbonImmutable::createFromTimestampUTC(filemtime(base_path($document['relative_path'])) ?: time())->toIso8601String(),
            ],
        ];
    }

    /**
     * @param  DocumentationRecord  $document
     */
    private function readContents(array $document): ?string
    {
        $contents = file_get_contents(base_path($document['relative_path']));

        return is_string($contents) ? $contents : null;
    }

    /**
     * @param  DocumentationRecord  $document
     */
    private function score(string $normalizedQuery, array $document, string $contents): int
    {
        if ($normalizedQuery === '') {
            return 1;
        }

        $score = 0;
        $title = Str::lower($document['title']);
        $description = Str::lower($document['description']);
        $identifier = Str::lower($document['id']);
        $body = Str::lower($contents);

        if (str_contains($title, $normalizedQuery)) {
            $score += 100;
        }

        if (str_contains($description, $normalizedQuery)) {
            $score += 50;
        }

        if (str_contains($identifier, $normalizedQuery)) {
            $score += 40;
        }

        if (str_contains($body, $normalizedQuery)) {
            $score += 20;
        }

        $tokens = array_values(array_filter(array_unique(preg_split('/\s+/', $normalizedQuery) ?: [])));

        foreach ($tokens as $token) {
            if (mb_strlen($token) < 2) {
                continue;
            }

            if (str_contains($title, $token)) {
                $score += 25;
            }

            if (str_contains($description, $token)) {
                $score += 10;
            }

            if (str_contains($body, $token)) {
                $score += 5;
            }
        }

        return $score;
    }
}
