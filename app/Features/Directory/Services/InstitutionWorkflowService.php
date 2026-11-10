<?php

declare(strict_types=1);

namespace App\Features\Directory\Services;

use App\Features\Directory\Actions\Institutions\ApplyInstitutionContributionAction;
use App\Features\Directory\Actions\Institutions\ChangeInstitutionStatusAction;
use App\Features\Directory\Actions\Institutions\CreateInstitutionAction;
use App\Features\Directory\Actions\Institutions\DetachInstitutionVerifierAction;
use App\Features\Directory\Actions\Institutions\EnforceSinglePrimaryInstitutionNameAction;
use App\Features\Directory\Actions\Institutions\GenerateInstitutionSlugAction;
use App\Features\Directory\Actions\Institutions\RecordInstitutionImportExclusionAction;
use App\Features\Directory\Actions\Institutions\RestoreInstitutionVerifierAction;
use App\Features\Directory\Actions\Institutions\SaveInstitutionAction;
use App\Features\Directory\Actions\Institutions\SyncInstitutionNamesAction;
use App\Features\Directory\Actions\Institutions\SyncInstitutionRelationsAction;
use App\Features\Directory\Actions\Institutions\VerifyPendingInstitutionsAction;
use App\Features\Directory\Api\InstitutionWorkflow;
use App\Features\Directory\Support\DirectoryModel;
use App\Shared\Auth\AccountActor;
use App\Shared\Auth\AccountDirectory;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Implements the institution contribution workflow through named actions.
 */
final readonly class InstitutionWorkflowService implements InstitutionWorkflow
{
    public function __construct(
        private AccountDirectory $accounts,
        private CreateInstitutionAction $createInstitution,
        private ApplyInstitutionContributionAction $applyInstitutionContribution,
        private SyncInstitutionNamesAction $syncInstitutionNames,
        private SyncInstitutionRelationsAction $syncInstitutionRelations,
        private VerifyPendingInstitutionsAction $verifyPending,
        private GenerateInstitutionSlugAction $generateInstitutionSlug,
        private RecordInstitutionImportExclusionAction $recordImportExclusion,
        private EnforceSinglePrimaryInstitutionNameAction $enforceSinglePrimaryName,
        private SaveInstitutionAction $saveInstitution,
        private ChangeInstitutionStatusAction $changeInstitutionStatus,
        private DetachInstitutionVerifierAction $detachVerifier,
        private RestoreInstitutionVerifierAction $restoreVerifier,
    ) {}

    public function createFromContribution(array $payload, string $proposerId): string
    {
        $actor = $this->accounts->resolveNotifiable($proposerId);

        $institution = $this->createInstitution->handle(
            $payload,
            $actor instanceof Model && $actor instanceof AccountActor ? $actor : null,
            verified: false,
            assignOwner: false,
        );

        return (string) $institution->getKey();
    }

    public function createFromForm(array $payload, ?string $actorId = null): string
    {
        $actor = $actorId !== null ? $this->accounts->resolveNotifiable($actorId) : null;

        $institution = $this->createInstitution->handle(
            $payload,
            $actor instanceof Model && $actor instanceof AccountActor ? $actor : null,
            verified: false,
            assignOwner: $actor instanceof Model && $actor instanceof AccountActor,
        );

        return (string) $institution->getKey();
    }

    public function saveFromAdmin(array $payload, string $actorId, ?string $institutionId = null): string
    {
        $actor = $this->accounts->resolveNotifiable($actorId);

        if (! $actor instanceof Model || ! $actor instanceof AccountActor) {
            throw new RuntimeException("Directory actor [{$actorId}] was not found.");
        }

        $institution = $institutionId !== null ? $this->institution($institutionId) : null;
        $saved = $this->saveInstitution->handle($payload, $actor, $institution);

        return (string) $saved->getKey();
    }

    public function createVerifiedFromContribution(array $payload): string
    {
        $institution = $this->createInstitution->handle($payload, verified: true);

        return (string) $institution->getKey();
    }

    public function applyContribution(string $institutionId, array $payload): array
    {
        return $this->applyInstitutionContribution->handle($this->institution($institutionId), $payload);
    }

    public function syncRelations(string $institutionId, array $payload): void
    {
        $this->syncInstitutionRelations->handle($this->institution($institutionId), $payload);
    }

    public function syncNames(string $institutionId, array $names): void
    {
        $this->syncInstitutionNames->handle($this->institution($institutionId), $names);
    }

    public function verifyPending(array $institutionIds): void
    {
        $this->verifyPending->handle($institutionIds);
    }

    public function changeStatus(string $institutionId, string $status): void
    {
        $this->changeInstitutionStatus->handle($institutionId, $status);
    }

    public function detachVerifier(string $accountId): void
    {
        $this->detachVerifier->handle($accountId);
    }

    public function restoreVerifier(string $accountId, array $institutionIds): void
    {
        $this->restoreVerifier->handle($accountId, $institutionIds);
    }

    public function syncSlugsForName(string $name, ?string $exceptInstitutionId = null): bool
    {
        return $this->generateInstitutionSlug->syncInstitutionSlugsForName($name, $exceptInstitutionId);
    }

    public function syncSlug(string $institutionId): bool
    {
        return $this->generateInstitutionSlug->syncInstitutionSlug($this->institution($institutionId));
    }

    public function recordImportExclusion(string $source, string $externalRef, string $institutionId): void
    {
        $this->recordImportExclusion->handle($source, $externalRef, $institutionId);
    }

    public function enforceSinglePrimaryName(string $nameId, string $institutionId, bool $isPrimary): void
    {
        $name = DirectoryModel::query('institution_name')->find($nameId);

        if (! $name instanceof Model) {
            return;
        }

        $name->setAttribute('is_primary', $isPrimary);
        $name->setAttribute('institution_id', $institutionId);
        $this->enforceSinglePrimaryName->handle($name);
    }

    private function institution(string $institutionId): Model
    {
        $institution = DirectoryModel::query('institution')->find($institutionId);

        if (! $institution instanceof Model) {
            throw new RuntimeException("Institution [{$institutionId}] was not found.");
        }

        return $institution;
    }
}
