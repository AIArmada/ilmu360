<?php

namespace App\Http\Controllers\Api\Frontend;

use App\Enums\EventTaxonomyCode;
use App\Enums\MemberSubjectType;
use App\Support\Api\Frontend\FrontendCatalogService;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Catalog', 'Public lookup catalogs for geography, tags, languages, references, venues, and write-flow selectors.')]
class CatalogController extends FrontendController
{
    public function __construct(
        private readonly FrontendCatalogService $catalogs,
    ) {}

    #[Endpoint(
        title: 'List public countries catalog',
        description: 'Returns the public countries catalog used by client and public write-flow selectors.',
    )]
    public function countries(): JsonResponse
    {
        return response()->json([
            'data' => $this->catalogs->countries(),
        ]);
    }

    #[QueryParameter('country_id', 'Package country UUID required for state options.', required: true, type: 'string', infer: false)]
    #[Endpoint(
        title: 'List public states catalog',
        description: 'Returns package addressing `states` rows for an explicit `country_id` (addresses.state_id).',
    )]
    public function states(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->catalogs->states(
                $request->filled('country_id') ? $request->string('country_id')->toString() : null,
            ),
        ]);
    }

    #[QueryParameter('state_id', 'Package state UUID (preferred).', required: false, type: 'string', infer: false)]
    #[QueryParameter('country_id', 'Optional country UUID when listing cities without a state filter.', required: false, type: 'string', infer: false)]
    #[Endpoint(
        title: 'List public cities catalog',
        description: 'Returns package addressing `cities` rows for a selected `state_id` or `country_id` (addresses.city_id).',
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
        title: 'List public districts catalog',
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
    #[QueryParameter('country_id', 'Optional address country UUID when listing without a parent.', required: false, type: 'string', infer: false)]
    #[Endpoint(
        title: 'List public subdistricts catalog',
        description: 'Returns the next country-profile administrative-area options for product `admin_area_2_id`.',
    )]
    public function adminAreaLevel2(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->catalogs->adminAreaLevel2(
                $request->filled('admin_area_1_id') ? $request->string('admin_area_1_id')->toString() : null,
                $request->filled('country_id') ? $request->string('country_id')->toString() : null,
                $request->filled('state_id') ? $request->string('state_id')->toString() : null,
            ),
        ]);
    }

    #[Endpoint(
        title: 'List languages catalog',
        description: 'Returns selectable language options for public and client-facing write flows.',
    )]
    public function languages(): JsonResponse
    {
        return response()->json([
            'data' => $this->catalogs->languages(),
        ]);
    }

    #[Endpoint(
        title: 'List taxonomy terms catalog',
        description: 'Returns package EventTerm options for a taxonomy code (domain, discipline, source, issue).',
    )]
    public function taxonomyTerms(string $type, Request $request): JsonResponse
    {
        $tagType = EventTaxonomyCode::tryFrom($type);
        abort_unless($tagType instanceof EventTaxonomyCode, 404);

        return response()->json([
            'data' => $this->catalogs->taxonomyTerms($tagType->value, $request->string('q')->toString()),
        ]);
    }

    #[Endpoint(
        title: 'List tags catalog',
        description: 'Alias of taxonomy-terms catalog (ADR-011). Prefer /taxonomy-terms/{type}.',
    )]
    public function tags(string $type, Request $request): JsonResponse
    {
        return $this->taxonomyTerms($type, $request);
    }

    #[Endpoint(
        title: 'List references catalog',
        description: 'Returns reference options for public search and write flows, optionally filtered by the `q` query parameter.',
    )]
    public function references(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->catalogs->references($request->string('q')->toString()),
        ]);
    }

    #[Endpoint(
        title: 'List institution submit selectors',
        description: 'Returns institution options available to the current client context for event submission flows.',
    )]
    public function submitInstitutions(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->catalogs->submitInstitutions(
                $this->currentUser($request),
                $request->string('q')->toString(),
            ),
        ]);
    }

    #[Endpoint(
        title: 'List speaker submit selectors',
        description: 'Returns speaker options available to the current client context for event submission flows.',
    )]
    public function submitSpeakers(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->catalogs->submitSpeakers(
                $this->currentUser($request),
                $request->string('q')->toString(),
            ),
        ]);
    }

    #[Endpoint(
        title: 'List venues catalog',
        description: 'Returns venue options for public search and write flows, optionally filtered by the `q` query parameter.',
    )]
    public function venues(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->catalogs->venues($request->string('q')->toString()),
        ]);
    }

    #[Endpoint(
        title: 'List spaces catalog',
        description: 'Returns global space options when no `institution_id` is selected, and returns global plus institution-linked spaces when an `institution_id` is provided for event flows.',
    )]
    public function spaces(Request $request): JsonResponse
    {
        $institutionId = $request->string('institution_id')->toString();

        return response()->json([
            'data' => $this->catalogs->spaces($institutionId !== '' ? $institutionId : null),
        ]);
    }

    #[Endpoint(
        title: 'List membership-claim subjects',
        description: 'Returns claimable public subjects for the requested membership-claim subject type, optionally filtered by `q`.',
    )]
    public function membershipClaimSubjects(string $subjectType, Request $request): JsonResponse
    {
        $resolvedSubjectType = MemberSubjectType::fromRouteSegment($subjectType);
        abort_unless($resolvedSubjectType?->isClaimable(), 404);

        return response()->json([
            'data' => $this->catalogs->membershipClaimSubjects(
                $resolvedSubjectType,
                $request->string('q')->toString(),
            ),
        ]);
    }

    #[Endpoint(
        title: 'List prayer institutions catalog',
        description: 'Returns selectable verified institutions for daily and Friday prayer preference fields.',
    )]
    public function prayerInstitutions(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->catalogs->prayerInstitutions($request->string('q')->toString()),
        ]);
    }

    #[Endpoint(
        title: 'List institution role options',
        description: 'Returns the institution role options available for institution workspace member-management flows.',
    )]
    public function institutionRoles(): JsonResponse
    {
        return response()->json([
            'data' => $this->catalogs->institutionRoleOptions(),
        ]);
    }
}
