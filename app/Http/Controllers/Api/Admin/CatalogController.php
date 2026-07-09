<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Support\Api\Frontend\FrontendCatalogService;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group(
    'Admin Catalog',
    'Authenticated catalog endpoints for schema-driven admin writes. '
    .'Use these lookups for package-native geography: country_id, state_id, city_id, admin_area_1_id (district) / admin_area_2_id (subdistrict).',
)]
class CatalogController extends Controller
{
    public function __construct(
        private readonly FrontendCatalogService $catalogs,
    ) {}

    #[Endpoint(
        title: 'List admin countries catalog',
        description: 'Returns country options for admin write flows that require a `country_id`. Each option also includes `iso2` and the configured public `key` when available.',
    )]
    public function countries(): JsonResponse
    {
        return response()->json([
            'data' => $this->catalogs->countries(),
        ]);
    }

    #[QueryParameter('country_id', 'Package country UUID required for states.', required: false, type: 'string', infer: false)]
    #[Endpoint(
        title: 'List admin states catalog',
        description: 'Returns package addressing states for a selected `country_id` (addresses.state_id).',
    )]
    public function states(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->catalogs->states(
                $request->filled('country_id') ? $request->string('country_id')->toString() : null,
            ),
        ]);
    }

    #[QueryParameter('state_id', 'Package state UUID.', required: false, type: 'string', infer: false)]
    #[QueryParameter('country_id', 'Optional country UUID.', required: false, type: 'string', infer: false)]
    #[Endpoint(
        title: 'List admin cities catalog',
        description: 'Returns package addressing cities for a selected `state_id` or `country_id` (addresses.city_id).',
    )]
    public function cities(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->catalogs->cities(
                $request->filled('state_id') ? $request->string('state_id')->toString() : null,
                $request->filled('country_id') ? $request->string('country_id')->toString() : null,
            ),
        ]);
    }

    #[QueryParameter('country_id', 'Package country UUID for country-scoped district listing.', required: false, type: 'string', infer: false)]
    #[QueryParameter('state_id', 'Package state UUID — preferred; returns districts under that state.', required: false, type: 'string', infer: false)]
    #[Endpoint(
        title: 'List admin districts catalog',
        description: 'Returns districts (AddressArea level 2) for product `admin_area_1_id`. Prefer `state_id`.',
    )]
    public function adminAreaLevel1(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->catalogs->adminAreaLevel1(
                $request->filled('country_id') ? $request->string('country_id')->toString() : null,
                $request->filled('state_id') ? $request->string('state_id')->toString() : null,
            ),
        ]);
    }

    #[QueryParameter('admin_area_1_id', 'District UUID (admin_area_1_id) for subdistrict listing.', required: false, type: 'string', infer: false)]
    #[QueryParameter('state_id', 'Optional package state UUID for federal-territory local areas.', required: false, type: 'string', infer: false)]
    #[QueryParameter('country_id', 'Optional country UUID for country-scoped level-3 listing.', required: false, type: 'string', infer: false)]
    #[Endpoint(
        title: 'List admin subdistricts catalog',
        description: 'Returns subdistricts (AddressArea level 3) for product `admin_area_2_id`.',
    )]
    public function adminAreaLevel2(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->catalogs->adminAreaLevel2(
                $request->filled('admin_area_1_id')
                    ? $request->string('admin_area_1_id')->toString()
                    : null,
                $request->filled('country_id')
                    ? $request->string('country_id')->toString()
                    : null,
                $request->filled('state_id')
                    ? $request->string('state_id')->toString()
                    : null,
            ),
        ]);
    }
}
