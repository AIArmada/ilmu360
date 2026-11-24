<?php

declare(strict_types=1);

namespace App\Features\Organizations\EntryPoints\Http;

use AIArmada\Organizations\Models\Organization;
use App\Features\DonationChannels\Api\Data\DonationChannelSummary;
use App\Features\DonationChannels\Api\DonationChannelDirectory;
use App\Features\DonationChannels\Api\Enums\DonationChannelStatus;
use App\Features\Organizations\Api\OrganizationAccess;
use App\Features\Organizations\Api\OrganizationCommands;
use App\Features\Organizations\Api\OrganizationMember;
use App\Features\Organizations\Api\OrganizationSummary;
use App\Features\Organizations\Api\OrganizationWorkspace;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

#[Group('Organizations', 'Public organization discovery and authenticated organization workspace endpoints.')]
final class OrganizationController extends Controller
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
        description: 'Returns one active, public organization by its slug, including its verified donation channels.',
    )]
    public function show(string $organizationKey): JsonResponse
    {
        $organization = Organization::query()
            ->public()
            ->where('slug', $organizationKey)
            ->firstOrFail();

        return response()->json([
            'data' => [
                ...$this->publicPayload($organization),
                'donation_channels' => $this->publicDonationChannels($organization),
            ],
        ]);
    }

    #[Endpoint(
        title: 'Create an organization',
        description: 'Creates an organization for the authenticated user and returns its initial workspace payload.',
    )]
    public function store(Request $request, OrganizationCommands $organizationCommands): JsonResponse
    {
        $actor = Auth::user();
        abort_unless($actor instanceof Model, 403);

        $organization = $organizationCommands->create((string) $actor->getKey(), $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]));

        return response()->json(['data' => $this->workspacePayload($organization)], 201);
    }

    #[Endpoint(
        title: 'Get the organization workspace',
        description: 'Returns the authenticated user\'s organization workspace, including accessible organizations, the selected organization, members, and role information.',
    )]
    public function workspace(
        OrganizationAccess $organizationAccess,
        OrganizationWorkspace $organizationWorkspace,
    ): JsonResponse {
        $actor = Auth::user();
        abort_unless($actor instanceof Model, 403);

        $accountId = (string) $actor->getKey();
        $organizations = $organizationWorkspace->organizationsFor($accountId);

        abort_unless($organizations !== [], 403);

        $selectedContext = $organizationAccess->current();
        abort_unless($selectedContext instanceof OrganizationSummary, 403);

        $selected = array_find(
            $organizations,
            static fn (OrganizationSummary $organization): bool => $organization->id === $selectedContext->id,
        );
        abort_unless($selected instanceof OrganizationSummary, 403);

        $members = $organizationWorkspace->membersFor($selected->id);

        return response()->json([
            'data' => [
                'organizations' => array_map($this->workspacePayload(...), $organizations),
                'selected_organization' => $this->workspacePayload($selected),
                'members' => array_map(static fn (OrganizationMember $member): array => [
                    'id' => $member->accountId,
                    'name' => $member->name,
                    'email' => $member->email,
                    'role' => $member->role,
                ], $members),
                'members_total' => $selected->memberCount,
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

    /**
     * @return list<array<string, mixed>>
     */
    private function publicDonationChannels(Organization $organization): array
    {
        return collect(app(DonationChannelDirectory::class)->forOwner('organization', (string) $organization->getKey()))
            ->filter(static fn (DonationChannelSummary $channel): bool => $channel->status === DonationChannelStatus::Verified)
            ->map(static fn (DonationChannelSummary $channel): array => [
                'id' => $channel->id,
                'label' => $channel->label,
                'method' => $channel->method,
                'method_display' => $channel->method_display,
                'recipient' => $channel->recipient,
                'payment_details' => $channel->payment_details,
                'bank_name' => $channel->bank_name,
                'bank_code' => $channel->bank_code,
                'account_number' => $channel->account_number,
                'duitnow_type' => $channel->duitnow_type,
                'duitnow_value' => $channel->duitnow_value,
                'ewallet_provider' => $channel->ewallet_provider,
                'ewallet_handle' => $channel->ewallet_handle,
                'is_default' => $channel->is_default,
                'qr_url' => $channel->qr_url,
                'qr_full_url' => $channel->qr_full_url,
                'currency' => $channel->currency,
                'iban' => $channel->iban,
                'swift_bic' => $channel->swift_bic,
                'routing_code' => $channel->routing_code,
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function workspacePayload(OrganizationSummary $organization): array
    {
        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'slug' => $organization->slug,
            'description' => $organization->description,
            'visibility' => $organization->visibility,
            'status' => $organization->status,
            'member_count' => $organization->memberCount,
            'created_by' => $organization->createdBy,
        ];
    }
}
