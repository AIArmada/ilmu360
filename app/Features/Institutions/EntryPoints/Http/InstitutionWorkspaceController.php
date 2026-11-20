<?php

namespace App\Features\Institutions\EntryPoints\Http;

use AIArmada\Membership\Enums\MemberRole;
use App\Features\Events\Api\Enums\EventVisibility;
use App\Features\Events\Api\EventScheduleReads;
use App\Features\Institutions\Models\Institution;
use App\Features\Membership\Api\MembershipCommands;
use App\Features\Membership\Api\MembershipDirectory;
use App\Features\Membership\Api\MemberSubjectType;
use App\Models\User;
use App\Platform\Http\FrontendController;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

#[Group('InstitutionWorkspace', 'Authenticated institution workspace endpoints for member-management and institution-scoped event listings.')]
class InstitutionWorkspaceController extends FrontendController
{
    public function __construct(
        private readonly MembershipDirectory $memberships,
        private readonly EventScheduleReads $eventReader,
    ) {}

    #[Endpoint(
        title: 'Get institution workspace',
        description: 'Returns the current user\'s institution workspace payload, including accessible institutions, the selected institution, events, members, and role options. When `institution_id` is omitted, the first accessible institution is selected automatically.',
    )]
    public function show(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);

        $availableInstitutions = $this->availableInstitutionsQuery($user)
            ->select([
                'institutions.id',
                'institutions.name',
            ])
            ->orderBy('name')
            ->get();

        abort_unless($availableInstitutions->isNotEmpty(), 403);

        $eventCounts = $this->eventReader->institutionEventCounts(
            $availableInstitutions->map(static fn (Institution $institution): string => (string) $institution->getKey())->all(),
        );

        foreach ($availableInstitutions as $availableInstitution) {
            $counts = $eventCounts[(string) $availableInstitution->getKey()] ?? ['total' => 0, 'public' => 0];
            $availableInstitution->setAttribute('events_count', $counts['total']);
            $availableInstitution->setAttribute('public_events_count', $counts['public']);
        }

        $institutionId = $this->normalizeInstitutionId($request->query('institution_id'));

        $institutionId ??= (string) $availableInstitutions->first()->getKey();

        $institution = $availableInstitutions->first(
            fn (Institution $availableInstitution): bool => (string) $availableInstitution->getKey() === $institutionId
        );

        if (! $institution instanceof Institution) {
            abort(403);
        }

        $eventSearch = trim((string) $request->query('event_search', ''));
        $eventStatus = $this->normalizeEventStatus((string) $request->query('event_status', 'all'));
        $eventVisibility = $this->normalizeEventVisibility((string) $request->query('event_visibility', 'all'));
        $eventSort = $this->normalizeEventSort((string) $request->query('event_sort', 'starts_desc'));
        $eventPerPage = $this->normalizeEventPerPage($request->integer('event_per_page', 8));

        $events = $this->institutionEvents($institution, $eventSearch, $eventStatus, $eventVisibility, $eventSort, $eventPerPage);
        $members = $this->institutionMembers($institution);
        $memberRoleState = collect($members->getCollection())
            ->mapWithKeys(fn (User $member): array => [
                (string) $member->getKey() => [
                    'roles' => $this->memberRoleNames($member),
                    'role_ids' => $this->memberRoleIds($member),
                    'is_owner' => $this->memberIsOwner($member),
                    'has_protected_role' => $this->memberHasProtectedRole($member),
                ],
            ])
            ->all();
        $canManageMembers = $this->userHasInstitutionManagementRole($user);

        return response()->json([
            'data' => [
                'institutions' => $availableInstitutions
                    ->map(fn (Institution $availableInstitution): array => [
                        'id' => $availableInstitution->getKey(),
                        'name' => $availableInstitution->name,
                        'display_name' => $availableInstitution->display_name,
                        'events_count' => $this->countValue($availableInstitution, 'events_count'),
                        'public_events_count' => $this->countValue($availableInstitution, 'public_events_count'),
                    ])->all(),
                'selected_institution' => [
                    'id' => $institution->getKey(),
                    'name' => $institution->name,
                    'display_name' => $institution->display_name,
                    'events_count' => $this->countValue($institution, 'events_count'),
                    'public_events_count' => $this->countValue($institution, 'public_events_count'),
                    'internal_events_count' => max(
                        $this->countValue($institution, 'events_count') - $this->countValue($institution, 'public_events_count'),
                        0,
                    ),
                ],
                'events' => $events['items'],
                'event_filters' => [
                    'search' => $eventSearch,
                    'status' => $eventStatus,
                    'visibility' => $eventVisibility,
                    'sort' => $eventSort,
                    'per_page' => $eventPerPage,
                ],
                'members' => $members->getCollection()->map(fn (User $member): array => [
                    'id' => $member->getKey(),
                    'name' => $member->name,
                    'email' => $member->email,
                    'roles' => data_get($memberRoleState, [(string) $member->getKey(), 'roles'], []),
                    'role_ids' => data_get($memberRoleState, [(string) $member->getKey(), 'role_ids'], []),
                    'is_owner' => (bool) data_get($memberRoleState, [(string) $member->getKey(), 'is_owner'], false),
                    'has_protected_role' => (bool) data_get($memberRoleState, [(string) $member->getKey(), 'has_protected_role'], false),
                ])->all(),
                'can_manage_members' => $canManageMembers,
                'institution_role_options' => $this->institutionRoleOptions(),
            ],
            'meta' => [
                'events_pagination' => [
                    'page' => $events['page'],
                    'per_page' => $events['per_page'],
                    'total' => $events['total'],
                    'last_page' => $events['last_page'],
                ],
                'members_pagination' => $this->paginatorData($members),
                'request_id' => $this->requestId($request),
            ],
        ]);
    }

    #[Endpoint(
        title: 'Add an institution member',
        description: 'Adds a member to the selected institution workspace using an email address and role identifier.',
    )]
    public function addMember(
        string $institutionId,
        Request $request,
        MembershipCommands $membershipCommands,
    ): JsonResponse {
        $user = $this->requireUser($request);
        $institution = $this->selectedInstitutionOrAbort($user, $institutionId);
        $this->ensureCanManageMembers($user);

        $validated = $request->validate([
            'email' => ['required', 'email'],
            'role_id' => $this->institutionMemberRoleRules(),
        ]);

        $member = User::query()
            ->whereLike('email', trim((string) $validated['email']))
            ->first();

        if (! $member instanceof User) {
            throw ValidationException::withMessages([
                'email' => __('We could not find a user with that email address.'),
            ]);
        }

        $membershipCommands->addMember(
            MemberSubjectType::Institution,
            (string) $institution->getKey(),
            (string) $member->getKey(),
            MemberRole::from((string) $validated['role_id'])->value,
        );

        return response()->json([
            'data' => [
                'member' => [
                    'id' => $member->getKey(),
                    'name' => $member->name,
                    'email' => $member->email,
                    'roles' => $this->memberRoleNamesForFreshMember($institution, $member),
                ],
            ],
            'meta' => [
                'request_id' => $this->requestId($request),
            ],
        ], 201);
    }

    #[Endpoint(
        title: 'Update an institution member role',
        description: 'Changes the selected member\'s role assignment inside the institution workspace.',
    )]
    public function updateMemberRole(
        string $institutionId,
        string $memberId,
        Request $request,
        MembershipCommands $membershipCommands,
    ): JsonResponse {
        $user = $this->requireUser($request);
        $institution = $this->selectedInstitutionOrAbort($user, $institutionId);
        $this->ensureCanManageMembers($user);

        $member = $institution->members()->whereKey($memberId)->first();
        abort_unless($member instanceof User, 404);

        if ($this->memberHasProtectedRole($member)) {
            throw ValidationException::withMessages([
                'member' => __('Owner roles can only be changed from the global roles screen.'),
            ]);
        }

        $validated = $request->validate([
            'role_id' => $this->institutionMemberRoleRules(),
        ]);

        $membershipCommands->changeMemberRole(
            MemberSubjectType::Institution,
            (string) $institution->getKey(),
            (string) $member->getKey(),
            MemberRole::from((string) $validated['role_id'])->value,
        );

        return response()->json([
            'data' => [
                'member' => [
                    'id' => $member->getKey(),
                    'name' => $member->name,
                    'email' => $member->email,
                    'roles' => $this->memberRoleNamesForFreshMember($institution, $member),
                ],
            ],
            'meta' => [
                'request_id' => $this->requestId($request),
            ],
        ]);
    }

    #[Endpoint(
        title: 'Remove an institution member',
        description: 'Removes a non-owner member from the selected institution workspace.',
    )]
    public function removeMember(
        string $institutionId,
        string $memberId,
        Request $request,
        MembershipCommands $membershipCommands,
    ): JsonResponse {
        $user = $this->requireUser($request);
        $institution = $this->selectedInstitutionOrAbort($user, $institutionId);
        $this->ensureCanManageMembers($user);

        $member = $institution->members()->whereKey($memberId)->first();
        abort_unless($member instanceof User, 404);

        if ($this->memberIsOwner($member)) {
            throw ValidationException::withMessages([
                'member' => __('Institution owners cannot be removed from this dashboard.'),
            ]);
        }

        try {
            $membershipCommands->removeMember(
                MemberSubjectType::Institution,
                (string) $institution->getKey(),
                (string) $member->getKey(),
            );
        } catch (RuntimeException) {
            throw ValidationException::withMessages([
                'member' => __('Owner roles can only be changed from the global roles screen.'),
            ]);
        }

        return response()->json([
            'data' => [
                'removed_member_id' => $memberId,
            ],
            'meta' => [
                'request_id' => $this->requestId($request),
            ],
        ]);
    }

    /**
     * @return Builder<Institution>
     */
    private function availableInstitutionsQuery(User $user): Builder
    {
        return Institution::query()->whereKey(
            $this->memberships->subjectIdsFor((string) $user->getKey(), MemberSubjectType::Institution),
        );
    }

    private function normalizeInstitutionId(mixed $institutionId): ?string
    {
        if (! is_string($institutionId) || trim($institutionId) === '') {
            return null;
        }

        return $institutionId;
    }

    private function normalizeEventStatus(string $value): string
    {
        return in_array($value, ['all', 'pending', 'draft', 'approved', 'needs_changes', 'rejected', 'cancelled'], true)
            ? $value
            : 'all';
    }

    private function normalizeEventVisibility(string $value): string
    {
        return in_array($value, ['all', EventVisibility::Public->value, EventVisibility::Unlisted->value, EventVisibility::Private->value], true)
            ? $value
            : 'all';
    }

    private function normalizeEventSort(string $value): string
    {
        return in_array($value, ['starts_desc', 'starts_asc', 'title_asc', 'title_desc', 'registrations_desc', 'pending_first'], true)
            ? $value
            : 'starts_desc';
    }

    private function normalizeEventPerPage(int|string $value): int
    {
        $perPage = (int) $value;

        return in_array($perPage, [8, 15, 25], true) ? $perPage : 8;
    }

    private function selectedInstitutionOrAbort(User $user, string $institutionId): Institution
    {
        $institution = $this->availableInstitutionsQuery($user)->whereKey($institutionId)->first();

        abort_unless($institution instanceof Institution, 404);

        return $institution;
    }

    private function ensureCanManageMembers(User $user): void
    {
        abort_unless($this->userHasInstitutionManagementRole($user), 403);
    }

    private function userHasInstitutionManagementRole(User $user): bool
    {
        return $this->memberships->hasAnyMembershipRole(
            (string) $user->getKey(),
            MemberSubjectType::Institution,
            [MemberRole::Owner->value, MemberRole::Admin->value],
        );
    }

    private function memberIsOwner(User $user): bool
    {
        if (! isset($user->pivot) || ! $user->pivot->role) {
            return $this->memberships->hasAnyMembershipRole(
                (string) $user->getKey(),
                MemberSubjectType::Institution,
                [MemberRole::Owner->value],
            );
        }

        return $user->pivot->role === MemberRole::Owner->value;
    }

    private function memberHasProtectedRole(User $user): bool
    {
        return $this->memberIsOwner($user);
    }

    /**
     * @return array{
     *     items: list<array<string, mixed>>,
     *     page: int,
     *     per_page: int,
     *     total: int,
     *     last_page: int
     * }
     */
    private function institutionEvents(
        Institution $institution,
        string $eventSearch,
        string $eventStatus,
        string $eventVisibility,
        string $eventSort,
        int $eventPerPage,
    ): array {
        return $this->eventReader->institutionEventsPage(
            (string) $institution->getKey(),
            $eventSearch,
            $eventStatus,
            $eventVisibility,
            $eventSort,
            $eventPerPage,
            max(1, (int) request()->query('page', 1)),
        );
    }

    /**
     * @return LengthAwarePaginator<int, User>
     */
    private function institutionMembers(?Institution $institution): LengthAwarePaginator
    {
        if (! $institution instanceof Institution) {
            /** @var LengthAwarePaginator<int, User> $paginator */
            $paginator = User::query()->whereRaw('1 = 0')->paginate(8);

            return $paginator;
        }

        /** @var LengthAwarePaginator<int, User> $paginator */
        $paginator = $institution->members()
            ->select('users.id', 'users.name', 'users.email')
            ->with('roles')
            ->orderBy('users.name')
            ->paginate(8);

        return $paginator;
    }

    /**
     * @return array<string, string>
     */
    private function institutionRoleOptions(): array
    {
        return collect(MemberRole::cases())
            ->reject(fn (MemberRole $role): bool => $role === MemberRole::Owner)
            ->mapWithKeys(fn (MemberRole $r): array => [$r->value => $r->label()])
            ->all();
    }

    /**
     * @return list<string|Rule>
     */
    private function institutionMemberRoleRules(): array
    {
        return [
            'required',
            'string',
            Rule::in([
                MemberRole::Admin->value,
                MemberRole::Editor->value,
                MemberRole::Viewer->value,
            ]),
        ];
    }

    /**
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @return array<string, int>
     */
    private function paginatorData(LengthAwarePaginator $paginator): array
    {
        return [
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }

    private function countValue(Institution $institution, string $key): int
    {
        return (int) data_get($institution, $key, 0);
    }

    /**
     * @return list<string>
     */
    private function memberRoleNames(User $member): array
    {
        $roleSlug = $member->pivot->role ?? '';

        if ($roleSlug === '') {
            return [];
        }

        return [MemberRole::tryFrom($roleSlug)?->label() ?? $roleSlug];
    }

    /**
     * @return list<string>
     */
    private function memberRoleIds(User $member): array
    {
        $roleSlug = $member->pivot->role ?? '';

        return $roleSlug !== '' ? [$roleSlug] : [];
    }

    /**
     * @return list<string>
     */
    private function memberRoleNamesForFreshMember(Institution $institution, User $member): array
    {
        $pivotMember = $institution->members()->where('user_id', $member->id)->first();
        $roleSlug = $pivotMember?->pivot->role ?? '';

        if ($roleSlug === '') {
            return [];
        }

        return [MemberRole::tryFrom($roleSlug)?->label() ?? $roleSlug];
    }
}
