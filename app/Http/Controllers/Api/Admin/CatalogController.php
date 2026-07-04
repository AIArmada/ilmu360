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
    .'Use these lookups for dependent geography fields such as country, state, district, and subdistrict identifiers.',
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

    #[QueryParameter('country_id', 'Optional package country UUID required by dependent first-level address-area selectors.', required: false, type: 'string', infer: false)]
    #[Endpoint(
        title: 'List admin states catalog',
        description: 'Returns state options for an admin write flow. '
            .'Pass `country_id` to resolve the states available for a selected country.',
    )]
    public function states(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->catalogs->states($request->filled('country_id') ? $request->string('country_id')->toString() : null),
        ]);
    }

    #[QueryParameter('admin_area_1_id', 'Optional package first-level address-area UUID required by dependent second-level selectors.', required: false, type: 'string', infer: false)]
    #[Endpoint(
        title: 'List admin districts catalog',
        description: 'Returns district options for an admin write flow. '
            .'Pass `state_id` to resolve the districts available for a selected state.',
    )]
    public function districts(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->catalogs->districts(
                $request->filled('admin_area_1_id')
                    ? $request->string('admin_area_1_id')->toString()
                    : ($request->filled('state_id') ? $request->string('state_id')->toString() : null),
            ),
        ]);
    }

    #[QueryParameter('admin_area_1_id', 'Optional package first-level address-area UUID.', required: false, type: 'string', infer: false)]
    #[QueryParameter('admin_area_2_id', 'Optional package second-level address-area UUID.', required: false, type: 'string', infer: false)]
    #[Endpoint(
        title: 'List admin subdistricts catalog',
        description: 'Returns subdistrict options for an admin write flow. '
            .'Pass `district_id` for district-based lookups, or `state_id` alone when the target state stores subdistricts without a district.',
    )]
    public function subdistricts(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->catalogs->subdistricts(
                $request->filled('admin_area_1_id')
                    ? $request->string('admin_area_1_id')->toString()
                    : ($request->filled('state_id') ? $request->string('state_id')->toString() : null),
                $request->filled('admin_area_2_id')
                    ? $request->string('admin_area_2_id')->toString()
                    : ($request->filled('district_id') ? $request->string('district_id')->toString() : null),
            ),
        ]);
    }
}
