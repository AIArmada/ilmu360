<?php

declare(strict_types=1);

namespace App\Features\Directory\Services;

use App\Features\Directory\Actions\Persons\ApplyPersonContributionAction;
use App\Features\Directory\Actions\Persons\BackfillPersonSlugsAction;
use App\Features\Directory\Actions\Persons\ChangePersonStatusAction;
use App\Features\Directory\Actions\Persons\CreatePersonAction;
use App\Features\Directory\Actions\Persons\DeletePersonAction;
use App\Features\Directory\Actions\Persons\DetachPersonVerifierAction;
use App\Features\Directory\Actions\Persons\GeneratePersonSlugAction;
use App\Features\Directory\Actions\Persons\ReorderFeaturedPersonsAction;
use App\Features\Directory\Actions\Persons\RestorePersonVerifierAction;
use App\Features\Directory\Actions\Persons\SavePersonAction;
use App\Features\Directory\Actions\Persons\SyncPersonAffiliationsAction;
use App\Features\Directory\Actions\Persons\SyncPersonNamesAction;
use App\Features\Directory\Actions\Persons\SyncPersonRelationsAction;
use App\Features\Directory\Actions\Persons\SyncPersonSearchProjectionAction;
use App\Features\Directory\Actions\Persons\SyncPersonTitleAssignmentsAction;
use App\Features\Directory\Actions\Persons\VerifyPendingPersonsAction;
use App\Features\Directory\Api\PersonWorkflow;
use App\Features\Directory\Support\DirectoryModel;
use App\Shared\Auth\AccountActor;
use App\Shared\Auth\AccountDirectory;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Implements the person workflow through named Directory actions.
 */
final readonly class PersonWorkflowService implements PersonWorkflow
{
    public function __construct(
        private AccountDirectory $accounts,
        private CreatePersonAction $createPerson,
        private SavePersonAction $savePerson,
        private ApplyPersonContributionAction $applyPersonContribution,
        private DeletePersonAction $deletePerson,
        private ChangePersonStatusAction $changePersonStatus,
        private VerifyPendingPersonsAction $verifyPending,
        private DetachPersonVerifierAction $detachVerifier,
        private RestorePersonVerifierAction $restoreVerifier,
        private GeneratePersonSlugAction $generatePersonSlug,
        private BackfillPersonSlugsAction $backfillPersonSlugs,
        private ReorderFeaturedPersonsAction $reorderFeaturedPersons,
        private SyncPersonNamesAction $syncPersonNames,
        private SyncPersonAffiliationsAction $syncPersonAffiliations,
        private SyncPersonTitleAssignmentsAction $syncPersonTitleAssignments,
        private SyncPersonRelationsAction $syncPersonRelations,
        private SyncPersonSearchProjectionAction $syncPersonSearchProjection,
    ) {}

    public function createFromContribution(array $payload, string $proposerId): string
    {
        $actor = $this->accounts->resolveNotifiable($proposerId);

        $person = $this->createPerson->handle(
            $payload,
            $actor instanceof Model && $actor instanceof AccountActor ? $actor : null,
            assignOwner: $actor instanceof Model && $actor instanceof AccountActor,
        );

        return (string) $person->getKey();
    }

    public function createFromForm(array $payload, ?string $actorId = null, bool $assignOwner = true): string
    {
        $actor = $actorId !== null ? $this->accounts->resolveNotifiable($actorId) : null;
        $actor = $actor instanceof Model && $actor instanceof AccountActor ? $actor : null;

        $person = $this->createPerson->handle(
            $payload,
            $actor,
            assignOwner: $assignOwner && $actor instanceof Model,
        );

        return (string) $person->getKey();
    }

    public function createAuthor(array $payload): string
    {
        $person = $this->createPerson->handle(
            $payload,
            assignOwner: false,
            defaultGender: false,
        );

        return (string) $person->getKey();
    }

    public function createVerifiedFromContribution(array $payload): string
    {
        $person = $this->createPerson->handle($payload, verified: true, syncRelations: false);

        return (string) $person->getKey();
    }

    public function saveFromAdmin(array $payload, string $actorId, ?string $personId = null, bool $assignOwner = true): string
    {
        $actor = $this->accounts->resolveNotifiable($actorId);

        if (! $actor instanceof Model || ! $actor instanceof AccountActor) {
            throw new RuntimeException("Directory actor [{$actorId}] was not found.");
        }

        $person = $personId !== null ? $this->person($personId) : null;
        $saved = $this->savePerson->handle($payload, $actor, $person, assignOwner: $assignOwner);

        return (string) $saved->getKey();
    }

    public function applyContribution(string $personId, array $payload): array
    {
        return $this->applyPersonContribution->handle($this->person($personId), $payload);
    }

    public function syncRelations(string $personId, array $payload): void
    {
        $this->syncPersonRelations->handle($this->person($personId), $payload);
    }

    public function syncNames(string $personId, mixed $names): void
    {
        $this->syncPersonNames->handle($this->person($personId), $names);
    }

    public function syncAffiliations(string $personId, mixed $institutions): void
    {
        $this->syncPersonAffiliations->handle($this->person($personId), $institutions);
    }

    public function syncTitleAssignments(string $personId, mixed $assignments): void
    {
        $this->syncPersonTitleAssignments->handle($this->person($personId), $assignments);
    }

    public function attachAffiliation(string $personId, string $institutionId, array $attributes): string
    {
        return $this->syncPersonAffiliations->attach($this->person($personId), $institutionId, $attributes);
    }

    public function updateAffiliation(string $personId, string $affiliationId, array $attributes): void
    {
        $this->syncPersonAffiliations->update($this->person($personId), $affiliationId, $attributes);
    }

    public function updateAffiliationForInstitution(string $personId, string $institutionId, array $attributes): void
    {
        $this->syncPersonAffiliations->updateForInstitution($this->person($personId), $institutionId, $attributes);
    }

    public function detachAffiliations(string $personId, array $institutionIds): int
    {
        return $this->syncPersonAffiliations->detach($this->person($personId), $institutionIds);
    }

    public function deleteAffiliations(string $personId, array $affiliationIds): int
    {
        return $this->syncPersonAffiliations->deleteRecords($this->person($personId), $affiliationIds);
    }

    public function saveName(string $personId, ?string $nameId, array $attributes): string
    {
        return $this->syncPersonNames->save($this->person($personId), $attributes, $nameId);
    }

    public function deleteNames(string $personId, array $nameIds): int
    {
        return $this->syncPersonNames->delete($this->person($personId), $nameIds);
    }

    public function verifyPending(array $personIds): void
    {
        $this->verifyPending->handle($personIds);
    }

    public function changeStatus(string $personId, string $status): void
    {
        $this->changePersonStatus->handle($personId, $status);
    }

    public function detachVerifier(string $accountId): void
    {
        $this->detachVerifier->handle($accountId);
    }

    public function restoreVerifier(string $accountId, array $personIds): void
    {
        $this->restoreVerifier->handle($accountId, $personIds);
    }

    public function syncSlugsForName(string $name): bool
    {
        return $this->generatePersonSlug->syncPersonSlugsForName($name);
    }

    public function syncSlug(string $personId): bool
    {
        return $this->generatePersonSlug->syncPersonSlug($this->person($personId));
    }

    public function syncSlugAfterAddressDeleted(string $personId, array $deletedAddress): bool
    {
        return $this->generatePersonSlug->syncPersonSlugAfterAddressDeleted($this->person($personId), $deletedAddress);
    }

    public function slugForPerson(string $personId): string
    {
        return $this->generatePersonSlug->forPerson($this->person($personId));
    }

    public function buildSlug(string $name, array $payload = [], ?string $ignorePersonId = null): string
    {
        return $this->generatePersonSlug->handle($name, $payload, $ignorePersonId);
    }

    public function backfillSlugs(): array
    {
        return $this->backfillPersonSlugs->handle();
    }

    public function reorderFeatured(array $order): void
    {
        $this->reorderFeaturedPersons->handle($order);
    }

    public function syncSearchProjection(string $personId, bool $bustCache = true): void
    {
        $this->syncPersonSearchProjection->sync($this->person($personId), $bustCache);
    }

    public function purgeSearchProjection(string $personId, bool $bustCache = true): void
    {
        // Purging can run from the model's deleted observer, after the row is gone.
        $this->syncPersonSearchProjection->purge($personId, $bustCache);
    }

    public function reindexSearch(int $chunkSize = 100): int
    {
        return $this->syncPersonSearchProjection->reindexAll($chunkSize);
    }

    public function delete(array $personIds): int
    {
        $deleted = 0;

        foreach (DirectoryModel::query('person')->whereKey($personIds)->get() as $person) {
            $this->deletePerson->handle($person);
            $deleted++;
        }

        return $deleted;
    }

    private function person(string $personId): Model
    {
        $person = DirectoryModel::query('person')->find($personId);

        if (! $person instanceof Model) {
            throw new RuntimeException("Person [{$personId}] was not found.");
        }

        return $person;
    }
}
