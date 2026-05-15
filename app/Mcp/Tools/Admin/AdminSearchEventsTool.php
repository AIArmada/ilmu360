<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Admin;

use App\Support\Mcp\McpEventSearchService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class AdminSearchEventsTool extends AbstractAdminTool
{
    protected string $name = 'admin-search-events';

    protected string $title = 'Search Events';

    protected string $description = 'Searches and filters events. Supports: keyword query with optional cross-entity expansion (institution/speaker/reference); geo/nearby search (lat, lng, radius_km, sort=distance); date range (starts_after, starts_before, time_scope); clock-time window (timing_mode=absolute, starts_time_from/until) or prayer-relative slot (timing_mode=prayer_relative, prayer_time); event type, format (physical/online/hybrid), and language; audience filters (gender, age_group, children_allowed, is_muslim_only); institution or venue; key-person filters by role (speaker, moderator, person_in_charge, imam, khatib, bilal) and their UUIDs; topic, domain/source/issue tag, reference UUIDs, and reference author filters; boolean flags (has_event_url, has_live_url, has_end_time). Each parameter description lists valid values.';

    public function __construct(
        private readonly McpEventSearchService $mcpEventSearchService,
    ) {}

    public function handle(Request $request): ResponseFactory|Response
    {
        return $this->safeResponse(function () use ($request): ResponseFactory {
            $this->authorizeAdmin($request);

            $validated = $this->validateArguments($request, $this->mcpEventSearchService->validationRules());

            return Response::structured($this->mcpEventSearchService->search($validated));
        });
    }

    /**
     * @return array<string, Type>
     */
    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return $this->mcpEventSearchService->schema($schema);
    }
}
