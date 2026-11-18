<?php

declare(strict_types=1);

namespace App\Features\Institutions\Services;

use App\Features\Institutions\Actions\ApplyInstitutionContributionAction;
use App\Features\Institutions\Actions\ChangeInstitutionStatusAction;
use App\Features\Institutions\Actions\CreateInstitutionAction;
use App\Features\Institutions\Actions\DeleteInstitutionAction;
use App\Features\Institutions\Actions\DetachInstitutionVerifierAction;
use App\Features\Institutions\Actions\EnforceSinglePrimaryInstitutionNameAction;
use App\Features\Institutions\Actions\GenerateInstitutionSlugAction;
use App\Features\Institutions\Actions\RecordInstitutionImportExclusionAction;
use App\Features\Institutions\Actions\RestoreInstitutionVerifierAction;
use App\Features\Institutions\Actions\SaveInstitutionAction;
use App\Features\Institutions\Actions\SyncInstitutionRelationsAction;
use App\Features\Institutions\Actions\VerifyPendingInstitutionsAction;
use App\Features\Institutions\Api\InstitutionWorkflow;
use App\Features\Institutions\Models\Institution;
use App\Features\Institutions\Models\InstitutionName;
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
        private SyncInstitutionRelationsAction $syncInstitutionRelations,
        private VerifyPendingInstitutionsAction $verifyPending,
        private GenerateInstitutionSlugAction $generateInstitutionSlug,
        private RecordInstitutionImportExclusionAction $recordImportExclusion,
        private EnforceSinglePrimaryInstitutionNameAction $enforceSinglePrimaryName,
        private SaveInstitutionAction $saveInstitution,
        private ChangeInstitutionStatusAction $changeInstitutionStatus,
        private DeleteInstitutionAction $deleteInstitution,
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

    public function verifyPending(array $institutionIds): void
    {
        $this->verifyPending->handle($institutionIds);
    }

    public function changeStatus(string $institutionId, string $status): void
    {
        $this->changeInstitutionStatus->handle($institutionId, $status);
    }

    public function verifiedIdsForAccount(string $accountId): array
    {
        return Institution::query()
            ->where('verified_by', $accountId)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
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

    public function syncSearchProjection(string $institutionId): void
    {
        $institution = Institution::query()->find($institutionId);

        if (! $institution instanceof Institution) {
            return;
        }

        if ($institution->shouldBeSearchable()) {
            $institution->searchable();

            return;
        }

        $institution->unsearchable();
    }

    public function recordImportExclusion(string $source, string $externalRef, string $institutionId): void
    {
        $this->recordImportExclusion->handle($source, $externalRef, $institutionId);
    }

    public function enforceSinglePrimaryName(string $nameId, string $institutionId, bool $isPrimary): void
    {
        $name = InstitutionName::query()->find($nameId);

        if (! $name instanceof Model) {
            return;
        }

        $name->setAttribute('is_primary', $isPrimary);
        $name->setAttribute('institution_id', $institutionId);
        $this->enforceSinglePrimaryName->handle($name);
    }

    public function delete(array $institutionIds): int
    {
        $deleted = 0;

        foreach (Institution::query()->whereKey($institutionIds)->get() as $institution) {
            if (! $institution instanceof Institution) {
                throw new RuntimeException('The registered institution model must be the Directory institution model.');
            }

            $this->deleteInstitution->handle($institution);
            $deleted++;
        }

        return $deleted;
    }

    private function institution(string $institutionId): Institution
    {
        $institution = Institution::query()->find($institutionId);

        if (! $institution instanceof Institution) {
            throw new RuntimeException("Institution [{$institutionId}] was not found.");
        }

        return $institution;
    }
}
