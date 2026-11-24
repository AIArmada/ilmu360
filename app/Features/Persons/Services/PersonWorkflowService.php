<?php

declare(strict_types=1);

namespace App\Features\Persons\Services;

use App\Features\Persons\Actions\ApplyPersonContributionAction;
use App\Features\Persons\Actions\ChangePersonStatusAction;
use App\Features\Persons\Actions\CreatePersonAction;
use App\Features\Persons\Actions\DeletePersonAction;
use App\Features\Persons\Actions\DetachPersonVerifierAction;
use App\Features\Persons\Actions\GeneratePersonSlugAction;
use App\Features\Persons\Actions\ReorderFeaturedPersonsAction;
use App\Features\Persons\Actions\RestorePersonVerifierAction;
use App\Features\Persons\Actions\SavePersonAction;
use App\Features\Persons\Actions\SyncPersonAffiliationsAction;
use App\Features\Persons\Actions\SyncPersonNamesAction;
use App\Features\Persons\Actions\SyncPersonRelationsAction;
use App\Features\Persons\Actions\SyncPersonSearchProjectionAction;
use App\Features\Persons\Actions\VerifyPendingPersonsAction;
use App\Features\Persons\Api\PersonWorkflow;
use App\Features\Persons\Models\Person;
use App\Platform\Media\MediaCollectionSync;
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
        private ReorderFeaturedPersonsAction $reorderFeaturedPersons,
        private SyncPersonNamesAction $syncPersonNames,
        private SyncPersonAffiliationsAction $syncPersonAffiliations,
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

    public function verifiedIdsForAccount(string $accountId): array
    {
        return Person::query()
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

        foreach (Person::query()->whereKey($personIds)->get() as $person) {
            if (! $person instanceof Person) {
                throw new RuntimeException('The registered person model must be the Directory person model.');
            }

            $this->deletePerson->handle($person);
            $deleted++;
        }

        return $deleted;
    }

    public function withContributionModel(string $personId, callable $callback): void
    {
        $callback(Person::query()->findOrFail($personId));
    }

    /** @param array<int|string, mixed> $gallery */
    public function syncContributionMedia(string $personId, mixed $avatar, mixed $square, mixed $profile, ?array $gallery): void
    {
        $person = Person::query()->findOrFail($personId);
        $sync = app(MediaCollectionSync::class);

        $sync->syncSingle($person, $avatar, 'avatar');
        $sync->syncSingle($person, $square, 'square');
        $sync->syncSingle($person, $profile, 'profile');
        $sync->syncMultiple($person, $gallery, 'gallery');
    }

    private function person(string $personId): Person
    {
        $person = Person::query()->find($personId);

        if (! $person instanceof Person) {
            throw new RuntimeException("Person [{$personId}] was not found.");
        }

        return $person;
    }
}
