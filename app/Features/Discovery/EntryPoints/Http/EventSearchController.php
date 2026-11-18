<?php

declare(strict_types=1);

namespace App\Features\Discovery\EntryPoints\Http;

use App\Http\Controllers\Controller;
use App\Features\Discovery\EntryPoints\Http\Requests\SearchAdminEventsRequest;
use App\Features\Events\EntryPoints\Mcp\McpEventSearchService;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group(
    'Admin Search',
    'Specialized search endpoints for admin resources.',
)]
class EventSearchController extends Controller
{
    public function __construct(
        private readonly McpEventSearchService $eventSearchService,
    ) {}

    #[Endpoint(
        title: 'Search admin events',
        description: 'Advanced event search with rich filtering, geo-proximity, and temporal scoping. Keyword query expands across event title and related institution/person/reference surfaces by default, with optional include toggles and reference-author filtering. Mirrors the admin-search-events MCP tool.',
    )]
    public function search(SearchAdminEventsRequest $request): JsonResponse
    {
        return response()->json($this->eventSearchService->search($request->validated()));
    }
}
