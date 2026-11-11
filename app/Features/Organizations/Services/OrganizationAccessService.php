<?php

declare(strict_types=1);

namespace App\Features\Organizations\Services;

use AIArmada\Organizations\Contracts\CurrentOrganizationResolver;
use AIArmada\Organizations\Contracts\OrganizationAuthorization;
use AIArmada\Organizations\Enums\OrganizationStatus;
use AIArmada\Organizations\Models\Organization;
use App\Features\Organizations\Api\OrganizationAccess;
use App\Features\Organizations\Api\OrganizationSummary;
use App\Platform\Models\MorphModelResolver;
use App\Shared\Membership\MembershipDirectory;
use App\Shared\Membership\MembershipSubjectType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Organization access adapter over the canonical package aggregate.
 *
 * Package models, actions and authorization stay canonical; this service owns
 * the feature's scalar read and authorization boundary.
 */
final readonly class OrganizationAccessService implements OrganizationAccess
{
    public function __construct(
        private MembershipDirectory $memberships,
        private CurrentOrganizationResolver $currentOrganization,
        private OrganizationAuthorization $authorization,
    ) {}

    public function current(): ?OrganizationSummary
    {
        $organization = $this->currentOrganization->resolve();

        return $organization instanceof Organization
            ? $this->summary($organization)
            : null;
    }

    public function find(string $identifier): ?OrganizationSummary
    {
        $organization = $this->query()
            ->where(function (Builder $query) use ($identifier): void {
                $query->whereKey($identifier)->orWhere('slug', $identifier);
            })
            ->withCount('members')
            ->first();

        return $organization instanceof Organization
            ? $this->summary($organization)
            : null;
    }

    public function findForAccount(string $accountId, string $identifier): ?OrganizationSummary
    {
        $organization = $this->query()
            ->where(function (Builder $query) use ($identifier): void {
                $query->whereKey($identifier)->orWhere('slug', $identifier);
            })
            ->whereHas('members', fn (Builder $query): Builder => $query->whereKey($accountId))
            ->withCount('members')
            ->first();

        return $organization instanceof Organization
            ? $this->summary($organization)
            : null;
    }

    public function accessibleFor(string $accountId): array
    {
        $organizationIds = $this->memberships->subjectIdsFor(
            $accountId,
            MembershipSubjectType::Organization,
        );

        if ($organizationIds === []) {
            return [];
        }

        return $this->query()
            ->whereKey($organizationIds)
            ->withCount('members')
            ->orderBy('name')
            ->get()
            ->map(fn (Organization $organization): OrganizationSummary => $this->summary($organization))
            ->all();
    }

    public function can(string $accountId, string $organizationId, string $ability): bool
    {
        $account = $this->account($accountId);
        $organization = $this->organization($organizationId);

        if (! $account instanceof Model || ! $organization instanceof Organization) {
            return false;
        }

        try {
            $this->authorization->authorize($account, $organization, $ability);

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }

    public function canCreateEvent(string $accountId, string $organizationId): bool
    {
        $account = $this->account($accountId);
        $organization = $this->organization($organizationId);

        return $account instanceof Model
            && $organization instanceof Organization
            && $organization->status === OrganizationStatus::Active
            && $organization->members()->whereKey($account->getKey())->exists();
    }

    public function authorizeCreateEvent(string $accountId, string $organizationId): void
    {
        if (! $this->canCreateEvent($accountId, $organizationId)) {
            throw new AuthorizationException('The actor cannot create events for this organization.');
        }
    }

    private function summary(Organization $organization): OrganizationSummary
    {
        $attributes = $organization->getAttributes();
        $memberCount = array_key_exists('members_count', $attributes)
            ? (int) $attributes['members_count']
            : $organization->members()->count();

        return new OrganizationSummary(
            id: (string) $organization->getKey(),
            name: (string) $organization->getAttribute('name'),
            slug: (string) $organization->getAttribute('slug'),
            description: $organization->getAttribute('description'),
            visibility: (string) $organization->getAttribute('visibility')->value,
            status: (string) $organization->getAttribute('status')->value,
            memberCount: $memberCount,
            createdBy: $organization->getAttribute('created_by'),
        );
    }

    /**
     * @return Builder<Organization>
     */
    private function query(): Builder
    {
        return Organization::query();
    }

    private function account(string $accountId): ?Model
    {
        $modelClass = MorphModelResolver::resolve('user');

        return $modelClass::query()->find($accountId);
    }

    private function organization(string $organizationId): ?Organization
    {
        return Organization::query()->find($organizationId);
    }
}
