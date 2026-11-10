<?php

declare(strict_types=1);

namespace App\Support\Directory;

use App\Actions\References\SaveReferenceAction;
use App\Actions\Spaces\SaveSpaceAction;
use App\Actions\Venues\SaveVenueAction;
use App\Features\Directory\Api\DirectoryCommands;
use App\Features\Directory\Api\DirectoryDetail;
use App\Features\Directory\Api\DirectoryReader;
use App\Features\Directory\Api\DirectoryRecordType;
use App\Features\Directory\Api\DonationChannelWorkflow;
use App\Features\Directory\Api\InstitutionWorkflow;
use App\Features\Directory\Api\PersonWorkflow;
use App\Models\DonationChannel;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\Space;
use App\Models\User;
use App\Models\Venue;
use App\Platform\Models\MorphModelResolver;
use App\Services\ContributionEntityMutationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use RuntimeException;

/**
 * Interim Directory write adapter over the canonical save actions.
 *
 * Every operation preserves the existing validation, media, relation,
 * lifecycle and ownership behavior. Administrative writes authorize the actor
 * through the owning resource policy; contribution composition operations keep
 * the caller's workflow authorization unless an actor is supplied.
 */
final readonly class EloquentDirectoryCommands implements DirectoryCommands
{
    public function __construct(
        private InstitutionWorkflow $institutionWorkflow,
        private PersonWorkflow $personWorkflow,
        private SaveReferenceAction $saveReference,
        private SaveVenueAction $saveVenue,
        private SaveSpaceAction $saveSpace,
        private DonationChannelWorkflow $donationChannelWorkflow,
        private ContributionEntityMutationService $entityMutations,
        private DirectoryReader $reader,
    ) {}

    public function create(DirectoryRecordType $type, array $attributes, string $actorId): DirectoryDetail
    {
        $actor = $this->actor($actorId);
        /** @var class-string<Model> $modelClass */
        $modelClass = MorphModelResolver::resolve($type->morphAlias());

        Gate::forUser($actor)->authorize('create', $modelClass);

        $model = match ($type) {
            DirectoryRecordType::Institution => Institution::query()->findOrFail($this->institutionWorkflow->saveFromAdmin($attributes, (string) $actor->getKey())),
            DirectoryRecordType::Person => Person::query()->findOrFail(
                $this->personWorkflow->saveFromAdmin($attributes, (string) $actor->getKey()),
            ),
            DirectoryRecordType::Reference => $this->saveReference->handle($attributes),
            DirectoryRecordType::Venue => $this->saveVenue->handle($attributes),
            DirectoryRecordType::Space => $this->saveSpace->handle($attributes),
            DirectoryRecordType::DonationChannel => DonationChannel::query()->findOrFail($this->donationChannelWorkflow->save($attributes)),
        };

        return $this->detail($type, $model);
    }

    public function update(DirectoryRecordType $type, string $recordId, array $attributes, string $actorId): DirectoryDetail
    {
        $actor = $this->actor($actorId);
        $record = $this->record($type, $recordId);

        Gate::forUser($actor)->authorize('update', $record);

        return $this->detail($type, $this->save($type, $record, $attributes, $actor));
    }

    public function delete(DirectoryRecordType $type, string $recordId, string $actorId): void
    {
        $actor = $this->actor($actorId);
        $record = $this->record($type, $recordId);

        Gate::forUser($actor)->authorize('delete', $record);

        if ($type === DirectoryRecordType::Person) {
            $this->personWorkflow->delete([(string) $record->getKey()]);

            return;
        }

        $record->delete();
    }

    public function apply(DirectoryRecordType $type, string $recordId, array $attributes, ?string $actorId = null): array
    {
        $record = $this->record($type, $recordId);
        $this->authorizeOptionalUpdate($actorId, $record);

        if (! in_array($type, [
            DirectoryRecordType::Institution,
            DirectoryRecordType::Person,
            DirectoryRecordType::Reference,
        ], true)) {
            throw new InvalidArgumentException("Contribution apply is not supported for [{$type->value}].");
        }

        if ($type === DirectoryRecordType::Person) {
            return $this->personWorkflow->applyContribution((string) $record->getKey(), $attributes);
        }

        return $this->entityMutations->apply($record, $attributes);
    }

    public function changeStatus(DirectoryRecordType $type, string $recordId, string $status, string $actorId): DirectoryDetail
    {
        return $this->update($type, $recordId, ['status' => $status], $actorId);
    }

    public function syncRelations(DirectoryRecordType $type, string $recordId, array $relations, ?string $actorId = null): void
    {
        $record = $this->record($type, $recordId);
        $this->authorizeOptionalUpdate($actorId, $record);

        match ($type) {
            DirectoryRecordType::Institution => $record instanceof Institution
                ? $this->entityMutations->syncInstitutionRelations($record, $relations)
                : throw new RuntimeException('Expected institution record.'),
            DirectoryRecordType::Person => $record instanceof Person
                ? $this->personWorkflow->syncRelations((string) $record->getKey(), $relations)
                : throw new RuntimeException('Expected speaker record.'),
            DirectoryRecordType::Reference => $record instanceof Reference
                ? $this->entityMutations->syncReferenceRelations($record, $relations)
                : throw new RuntimeException('Expected reference record.'),
            DirectoryRecordType::Venue => $record instanceof Venue
                ? $this->entityMutations->syncVenueRelations($record, $relations)
                : throw new RuntimeException('Expected venue record.'),
            DirectoryRecordType::Space, DirectoryRecordType::DonationChannel => throw new InvalidArgumentException(
                "Relation synchronization is not supported for [{$type->value}]."
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    /**
     * @param  array<string, mixed>  $attributes
     */
    private function save(DirectoryRecordType $type, Model $record, array $attributes, User $actor): Model
    {
        return match ($type) {
            DirectoryRecordType::Institution => $record instanceof Institution
                ? Institution::query()->findOrFail($this->institutionWorkflow->saveFromAdmin($attributes, (string) $actor->getKey(), (string) $record->getKey()))
                : throw new RuntimeException('Expected institution record.'),
            DirectoryRecordType::Person => $record instanceof Person
                ? Person::query()->findOrFail(
                    $this->personWorkflow->saveFromAdmin($attributes, (string) $actor->getKey(), (string) $record->getKey()),
                )
                : throw new RuntimeException('Expected speaker record.'),
            DirectoryRecordType::Reference => $record instanceof Reference
                ? $this->saveReference->handle($attributes, $record)
                : throw new RuntimeException('Expected reference record.'),
            DirectoryRecordType::Venue => $record instanceof Venue
                ? $this->saveVenue->handle($attributes, $record)
                : throw new RuntimeException('Expected venue record.'),
            DirectoryRecordType::Space => $record instanceof Space
                ? $this->saveSpace->handle($attributes, $record)
                : throw new RuntimeException('Expected space record.'),
            DirectoryRecordType::DonationChannel => $record instanceof DonationChannel
                ? DonationChannel::query()->findOrFail($this->donationChannelWorkflow->save($attributes, (string) $record->getKey()))
                : throw new RuntimeException('Expected donation channel record.'),
        };
    }

    private function detail(DirectoryRecordType $type, Model $record): DirectoryDetail
    {
        return $this->reader->find($type, (string) $record->getKey())
            ?? throw new RuntimeException("Directory record [{$type->value}:{$record->getKey()}] could not be re-read.");
    }

    private function record(DirectoryRecordType $type, string $recordId): Model
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = MorphModelResolver::resolve($type->morphAlias());
        $record = $modelClass::query()->find($recordId);

        if (! $record instanceof Model) {
            throw new InvalidArgumentException("Directory record [{$type->value}:{$recordId}] was not found.");
        }

        return $record;
    }

    private function actor(string $actorId): User
    {
        $actor = User::query()->find($actorId);

        if (! $actor instanceof User) {
            throw new InvalidArgumentException("Directory actor [{$actorId}] was not found.");
        }

        return $actor;
    }

    private function authorizeOptionalUpdate(?string $actorId, Model $record): void
    {
        if ($actorId === null || $actorId === '') {
            return;
        }

        Gate::forUser($this->actor($actorId))->authorize('update', $record);
    }
}
