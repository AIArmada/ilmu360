<?php

namespace App\Http\Controllers\Api\Frontend;

use App\Enums\MemberSubjectType;
use App\Enums\TagType;
use App\Support\Api\Frontend\FrontendCatalogService;
use App\Support\Location\PreferredCountryResolver;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
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

    #[Endpoint(
        title: 'List public states catalog',
        description: 'Returns the public states catalog for a selected `country_id`, or the current public-country scope when `country_id` is omitted.',
    )]
    public function states(Request $request): JsonResponse
    {
        $countryId = $request->filled('country_id')
            ? $request->integer('country_id')
            : app(PreferredCountryResolver::class)->resolveId($request);

        return response()->json([
            'data' => $this->catalogs->states($countryId),
        ]);
    }

    #[Endpoint(
        title: 'List public districts catalog',
        description: 'Returns the public districts catalog for a selected `state_id`, or the current public-country scope when `state_id` is omitted.',
    )]
    public function districts(Request $request): JsonResponse
    {
        $stateId = $request->filled('state_id') ? $request->integer('state_id') : null;
        $countryId = $stateId === null
            ? ($request->filled('country_id')
                ? $request->integer('country_id')
                : app(PreferredCountryResolver::class)->resolveId($request))
            : null;

        return response()->json([
            'data' => $this->catalogs->districts($stateId, $countryId),
        ]);
    }

    #[Endpoint(
        title: 'List public subdistricts catalog',
        description: 'Returns the public subdistricts catalog for a selected `district_id` or state fallback.',
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
        title: 'List tags catalog',
        description: 'Returns tag options for the requested tag type, optionally filtered by the `q` query parameter.',
    )]
    public function tags(string $type, Request $request): JsonResponse
    {
        $tagType = TagType::tryFrom($type);
        abort_unless($tagType instanceof TagType, 404);

        return response()->json([
            'data' => $this->catalogs->tags($tagType, $request->string('q')->toString()),
        ]);
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
