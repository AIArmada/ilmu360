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
    .'Use these lookups for package-native geography: country_id, optional state_id, city_id, and country-profile-defined admin_area_1_id through admin_area_4_id.',
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

    #[QueryParameter('country_id', 'Address country UUID for country-scoped administrative-area listing.', required: false, type: 'string', infer: false)]
    #[QueryParameter('state_id', 'Optional package State UUID or country-profile parent for the first administrative-area level.', required: false, type: 'string', infer: false)]
    #[Endpoint(
        title: 'List admin districts catalog',
        description: 'Returns the country profile\'s first administrative-area options for product `admin_area_1_id`.',
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

    #[QueryParameter('admin_area_1_id', 'Optional first administrative-area UUID used as the parent for the next configured level.', required: false, type: 'string', infer: false)]
    #[QueryParameter('state_id', 'Optional package State UUID or country-profile parent when the previous area is not selected.', required: false, type: 'string', infer: false)]
    #[QueryParameter('country_id', 'Optional address country UUID for country-scoped listing without a parent.', required: false, type: 'string', infer: false)]
    #[Endpoint(
        title: 'List admin subdistricts catalog',
        description: 'Returns the next country-profile administrative-area options for product `admin_area_2_id`.',
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
