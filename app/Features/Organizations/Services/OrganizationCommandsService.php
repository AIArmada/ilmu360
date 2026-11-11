<?php

declare(strict_types=1);

namespace App\Features\Organizations\Services;

use AIArmada\Organizations\Actions\ArchiveOrganizationAction;
use AIArmada\Organizations\Actions\CreateOrganizationAction;
use AIArmada\Organizations\Actions\MakeOrganizationPrivateAction;
use AIArmada\Organizations\Actions\MakeOrganizationPublicAction;
use AIArmada\Organizations\Actions\RestoreOrganizationAction;
use AIArmada\Organizations\Actions\SuspendOrganizationAction;
use AIArmada\Organizations\Actions\TransferOrganizationOwnershipAction;
use AIArmada\Organizations\Models\Organization;
use App\Features\Organizations\Api\OrganizationAccess;
use App\Features\Organizations\Api\OrganizationCommands;
use App\Features\Organizations\Api\OrganizationSummary;
use App\Platform\Models\MorphModelResolver;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Organization command adapter over the canonical package actions.
 *
 * Authorization, transactions and lifecycle hooks remain inside those actions;
 * this service only resolves scalar account/aggregate identifiers for the
 * feature boundary.
 */
final readonly class OrganizationCommandsService implements OrganizationCommands
{
    public function __construct(
        private OrganizationAccess $access,
        private CreateOrganizationAction $createOrganization,
        private MakeOrganizationPublicAction $makePublic,
        private MakeOrganizationPrivateAction $makePrivate,
        private SuspendOrganizationAction $suspend,
        private RestoreOrganizationAction $restore,
        private ArchiveOrganizationAction $archive,
        private TransferOrganizationOwnershipAction $transferOwnership,
    ) {}

    public function create(string $actorId, array $attributes): OrganizationSummary
    {
        $organization = $this->createOrganization->handle($this->account($actorId), $attributes);

        return $this->summary((string) $organization->getKey());
    }

    public function makePublic(string $organizationId, string $actorId): OrganizationSummary
    {
        $organization = $this->organization($organizationId);
        $this->makePublic->handle($organization, $this->account($actorId));

        return $this->summary($organizationId);
    }

    public function makePrivate(string $organizationId, string $actorId): OrganizationSummary
    {
        $organization = $this->organization($organizationId);
        $this->makePrivate->handle($organization, $this->account($actorId));

        return $this->summary($organizationId);
    }

    public function suspend(string $organizationId, string $actorId): OrganizationSummary
    {
        $organization = $this->organization($organizationId);
        $this->suspend->handle($organization, $this->account($actorId));

        return $this->summary($organizationId);
    }

    public function restore(string $organizationId, string $actorId): OrganizationSummary
    {
        $organization = $this->organization($organizationId);
        $this->restore->handle($organization, $this->account($actorId));

        return $this->summary($organizationId);
    }

    public function archive(string $organizationId, string $actorId): OrganizationSummary
    {
        $organization = $this->organization($organizationId);
        $this->archive->handle($organization, $this->account($actorId));

        return $this->summary($organizationId);
    }

    public function transferOwnership(
        string $organizationId,
        string $actorId,
        string $newOwnerId,
    ): OrganizationSummary {
        $organization = $this->organization($organizationId);

        $this->transferOwnership->handle(
            $organization,
            $this->account($actorId),
            $this->account($newOwnerId),
        );

        return $this->summary($organizationId);
    }

    private function summary(string $organizationId): OrganizationSummary
    {
        return $this->access->find($organizationId)
            ?? throw new RuntimeException('The organization could not be reloaded after its transition.');
    }

    private function organization(string $organizationId): Organization
    {
        return Organization::query()->findOrFail($organizationId);
    }

    private function account(string $accountId): Model
    {
        $modelClass = MorphModelResolver::resolve('user');

        return $modelClass::query()->findOrFail($accountId);
    }
}
