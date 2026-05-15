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

    #[QueryParameter('country_id', 'Optional country filter required by dependent state selectors.', required: false, type: 'integer', infer: false, example: 132)]
    #[Endpoint(
        title: 'List admin states catalog',
        description: 'Returns state options for an admin write flow. '
            .'Pass `country_id` to resolve the states available for a selected country.',
    )]
    public function states(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->catalogs->states($request->filled('country_id') ? $request->integer('country_id') : null),
        ]);
    }

    #[QueryParameter('state_id', 'Optional state filter required by dependent district selectors.', required: false, type: 'integer', infer: false, example: 14)]
    #[Endpoint(
        title: 'List admin districts catalog',
        description: 'Returns district options for an admin write flow. '
            .'Pass `state_id` to resolve the districts available for a selected state.',
    )]
    public function districts(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->catalogs->districts($request->filled('state_id') ? $request->integer('state_id') : null),
        ]);
    }

    #[QueryParameter('state_id', 'Optional state filter used when the target state is a federal territory.', required: false, type: 'integer', infer: false, example: 16)]
    #[QueryParameter('district_id', 'Optional district filter used for regular state or district scoped subdistrict lookups.', required: false, type: 'integer', infer: false, example: 103)]
    #[Endpoint(
        title: 'List admin subdistricts catalog',
        description: 'Returns subdistrict options for an admin write flow. '
            .'Pass `district_id` for district-based lookups, or `state_id` alone when the target state stores subdistricts without a district.',
    )]
    public function subdistricts(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->catalogs->subdistricts(
                $request->filled('state_id') ? $request->integer('state_id') : null,
                $request->filled('district_id') ? $request->integer('district_id') : null,
            ),
        ]);
    }
}
