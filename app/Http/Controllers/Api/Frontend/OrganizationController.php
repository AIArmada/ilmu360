<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Frontend;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Organizations\Actions\CreateOrganizationAction;
use AIArmada\Organizations\Models\Organization;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

#[Group('Organizations', 'Public organization discovery and authenticated organization workspace endpoints.')]
final class OrganizationController extends FrontendController
{
    #[Endpoint(
        title: 'List public organizations',
        description: 'Returns active, public organizations with optional name search and pagination.',
    )]
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));

        $organizations = Organization::query()
            ->public()
            ->when($search !== '', fn (Builder $query): Builder => $query->whereLike('name', "%{$search}%"))
            ->orderBy('name')
            ->paginate(min(max($request->integer('per_page', 20), 1), 50));

        return response()->json([
            'data' => $organizations->getCollection()->map(fn (Organization $organization): array => $this->publicPayload($organization))->values()->all(),
            'meta' => [
                'pagination' => [
                    'current_page' => $organizations->currentPage(),
                    'per_page' => $organizations->perPage(),
                    'last_page' => $organizations->lastPage(),
                    'total' => $organizations->total(),
                ],
            ],
        ]);
    }

    #[Endpoint(
        title: 'Get a public organization',
        description: 'Returns one active, public organization by its slug.',
    )]
    public function show(string $organizationKey): JsonResponse
    {
        $organization = Organization::query()
            ->public()
            ->where('slug', $organizationKey)
            ->firstOrFail();

        return response()->json(['data' => $this->publicPayload($organization)]);
    }

    #[Endpoint(
        title: 'Create an organization',
        description: 'Creates an organization for the authenticated user and returns its initial workspace payload.',
    )]
    public function store(Request $request, CreateOrganizationAction $createOrganization): JsonResponse
    {
        $actor = Auth::user();
        abort_unless($actor instanceof Model, 403);

        $organization = $createOrganization->handle($actor, $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]));

        return response()->json(['data' => $this->workspacePayload($organization)], 201);
    }

    #[Endpoint(
        title: 'Get the organization workspace',
        description: 'Returns the authenticated user\'s organization workspace, including accessible organizations, the selected organization, members, and role information.',
    )]
    public function workspace(): JsonResponse
    {
        $actor = Auth::user();
        abort_unless($actor instanceof Model, 403);

        $organizations = Organization::query()
            ->whereHas('members', fn (Builder $query): Builder => $query->whereKey($actor->getKey()))
            ->withCount('members')
            ->orderBy('name')
            ->get();

        abort_unless($organizations->isNotEmpty(), 403);

        $selectedContext = OwnerContext::resolve();
        abort_unless($selectedContext instanceof Organization, 403);

        $selected = $organizations->first(fn (Organization $organization): bool => $organization->is($selectedContext));
        abort_unless($selected instanceof Organization, 403);

        return response()->json([
            'data' => [
                'organizations' => $organizations->map(fn (Organization $organization): array => $this->workspacePayload($organization))->all(),
                'selected_organization' => $this->workspacePayload($selected),
                'members' => $selected->members()->get()->map(fn (Model $member): array => [
                    'id' => $member->getKey(),
                    'name' => $member->getAttribute('name'),
                    'email' => $member->getAttribute('email'),
                    'role' => data_get($member->getRelationValue('pivot'), 'role'),
                ])->all(),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function publicPayload(Organization $organization): array
    {
        return [
            'id' => $organization->getKey(),
            'name' => $organization->name,
            'slug' => $organization->slug,
            'description' => $organization->description,
            'visibility' => $organization->visibility->value,
        ];
    }

    /** @return array<string, mixed> */
    private function workspacePayload(Organization $organization): array
    {
        $attributes = $organization->getAttributes();
        $memberCount = array_key_exists('members_count', $attributes)
            ? (int) $attributes['members_count']
            : $organization->members()->count();

        return [
            ...$this->publicPayload($organization),
            'status' => $organization->status->value,
            'member_count' => $memberCount,
            'created_by' => $organization->created_by,
        ];
    }
}
