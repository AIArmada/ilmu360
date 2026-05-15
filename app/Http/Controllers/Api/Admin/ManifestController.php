<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Support\Api\Admin\AdminResourceService;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

class ManifestController extends Controller
{
    public function __construct(
        private readonly AdminResourceService $resourceService,
    ) {}

    #[Group('Admin Manifest', 'Machine-readable admin resource discovery and write-workflow guidance for authenticated Filament-admin users.')]
    #[Endpoint(
        title: 'List admin resources and write support',
        description: 'Returns the authenticated admin resource manifest. '
            .'Access follows the same live admin-panel access rule as the Filament UI, so bearer token abilities do not grant admin access by themselves. '
            .'Use the `write_support` flags here to discover which resources expose schema, create, and update support through the generic admin API. '
            .'The manifest also includes machine-readable write workflow guidance, docs URLs, and admin catalog endpoints for AI consumers.',
    )]
    public function __invoke(): JsonResponse
    {
        return response()->json($this->resourceService->manifest());
    }
}
