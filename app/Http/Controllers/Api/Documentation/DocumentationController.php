<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Documentation;

use App\Http\Controllers\Controller;
use App\Support\Documentation\DocumentationLibrary;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group(
    'Documentation Library',
    'Curated machine-readable access to restored ilmu360 documentation pages for API and MCP clients.',
    weight: 11,
)]
class DocumentationController extends Controller
{
    public function __construct(
        private readonly DocumentationLibrary $documentationLibrary,
    ) {}

    #[Endpoint(
        title: 'List curated documentation pages',
        description: 'Returns the curated documentation library exposed by the application. Use this when an API client needs the stable ids, summaries, and fetch endpoints for restored documentation pages.',
    )]
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => [
                'documents' => $this->documentationLibrary->apiLibrary(),
            ],
        ]);
    }

    #[Endpoint(
        title: 'Fetch curated documentation page',
        description: 'Returns the full text of a curated documentation page by stable id. Use the list endpoint first to discover valid ids and summaries.',
    )]
    public function show(string $documentId): JsonResponse
    {
        $document = $this->documentationLibrary->apiDocument($documentId);

        abort_unless($document !== null, 404);

        return response()->json([
            'data' => $document,
        ]);
    }
}
