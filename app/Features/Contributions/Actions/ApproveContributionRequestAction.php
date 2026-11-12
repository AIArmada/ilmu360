<?php

namespace App\Features\Contributions\Actions;

use App\Features\Contributions\Support\ContributionModel;
use App\Features\Directory\Api\DirectoryContributionMedia;
use App\Features\Directory\Api\InstitutionWorkflow;
use App\Features\Directory\Api\PersonWorkflow;
use App\Features\Directory\Api\ReferenceWorkflow;
use App\Features\Events\Api\EventAuthoring;
use App\Features\Events\Api\EventModeration;
use App\Features\Membership\Api\MembershipCommands;
use App\Platform\Concurrency\MapsIdentityLock;
use App\Platform\Location\AddressAssignments;
use App\Platform\Media\MediaCollectionSync;
use App\Shared\Contributions\ContributionRequestNotifier;
use App\Shared\Contributions\Enums\ContributionRequestStatus;
use App\Shared\Contributions\Enums\ContributionRequestType;
use App\Shared\Contributions\Enums\ContributionSubjectType;
use App\Shared\Contributions\Exceptions\ContributionRequestAlreadyReviewedException;
use App\Shared\Contributions\Exceptions\ContributionRequestNotPendingException;
use App\Shared\Contributions\InstitutionClaimGuard;
use App\Shared\Membership\MembershipSubjectType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class ApproveContributionRequestAction
{
    use AsAction;

    public function __construct(
        private readonly EventModeration $eventModeration,
        private readonly DirectoryContributionMedia $directoryContributionMedia,
        private readonly EventAuthoring $eventAuthoring,
        private readonly ContributionRequestNotifier $contributionRequestNotifications,
        private readonly MembershipCommands $membershipCommands,
        private readonly InstitutionWorkflow $institutionWorkflow,
        private readonly PersonWorkflow $personWorkflow,
        private readonly ReferenceWorkflow $referenceWorkflow,
        private readonly InstitutionClaimGuard $institutionClaims,
        private readonly MapsIdentityLock $mapsIdentityLock,
        private readonly MediaCollectionSync $mediaCollectionSync,
    ) {}

    /**
     * @param  \App\Models\ContributionRequest  $request
     * @return \App\Models\ContributionRequest
     */
    public function handle(Model $request, Model $reviewer, ?string $reviewerNote = null): Model
    {
        if (! $request->isPending()) {
            throw new ContributionRequestNotPendingException('Only pending contribution requests can be approved.');
        }

        $approve = function () use ($request, $reviewer, $reviewerNote): void {
            DB::transaction(function () use ($request, $reviewer, $reviewerNote): void {
                ContributionModel::query('contribution_request')->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
                $request->refresh();

                if (! $request->isPending()) {
                    throw new ContributionRequestNotPendingException('Only pending contribution requests can be approved.');
                }

                if ($request->type === ContributionRequestType::Create) {
                    $entity = $this->approveCreateRequest($request);

                    $request->forceFill([
                        'entity_type' => $entity->getMorphClass(),
                        'entity_id' => (string) $entity->getKey(),
                    ]);
                } else {
                    $this->applyApprovedUpdate($request);
                }

                $now = now();

                $request->forceFill([
                    'reviewer_id' => $reviewer->getKey(),
                    'reviewer_note' => $reviewerNote,
                    'status' => ContributionRequestStatus::Approved,
                    'reviewed_at' => $now,
                    'approved_at' => $now,
                    'last_state_change_at' => $now,
                ])->save();
            });
        };

        if ($request->subject_type === ContributionSubjectType::Institution) {
            /** @var array<string, mixed> $proposed */
            $proposed = $request->proposed_data ?? [];

            // Serialize same-identity submits and approvals with the submit
            // path's Maps identity lock (derived from the raw proposed
            // address on purpose, for parity). Cross-identity races are
            // backstopped by the request row lock below plus the pending-claim
            // check, since the lock key cannot cover identities the proposal
            // does not carry.
            $this->mapsIdentityLock->handle(
                ['address' => $this->addressPayload($proposed)],
                function () use ($approve, $request, $proposed): void {
                    $request->refresh();

                    if ($request->status === ContributionRequestStatus::Approved) {
                        // A second approver got here on a stale Pending snapshot
                        // after the first approval committed. Name the outcome
                        // precisely; the API resolves a fresh model so it
                        // practically never reaches this branch (direct callers
                        // with stale snapshots do).
                        throw new ContributionRequestAlreadyReviewedException(__('This request has already been reviewed. Please refresh to see its current status.'));
                    }

                    if (! $request->isPending()) {
                        throw new ContributionRequestNotPendingException('Only pending contribution requests can be approved.');
                    }

                    foreach ($this->conflictingClaimProbes($request, $proposed) as $probe) {
                        $this->assertNoConflictingInstitutionClaim($request, $probe);
                    }

                    $approve();
                }
            );
        } else {
            $approve();
        }

        $freshRequest = $request->fresh(['entity', 'proposer', 'reviewer']) ?? $request;

        $this->contributionRequestNotifications->notifyCreateRequestApproved($freshRequest);

        return $freshRequest;
    }

    /**
     * Probe states the approval re-check evaluates: the submitted identity,
     * except that creates audit the staged entity's current name and
     * addresses (an admin may have corrected them after submission), and
     * name-only updates fall back to the target entity's current addresses
     * for locality since the proposal carries none.
     *
     * Every probe address is pre-normalized with pure array reads: the guard
     * must not re-run persistence normalization on them, which would throw on
     * stale geography and could trigger remote Maps lookups per check.
     *
     * @param  \App\Models\ContributionRequest  $request
     * @param  array<string, mixed>  $proposed
     * @return list<array{name: mixed, address: array<string, mixed>}>
     */
    private function conflictingClaimProbes(Model $request, array $proposed): array
    {
        $entity = $request->entity;

        if ($request->type === ContributionRequestType::Create
            && $entity instanceof Model
            && ContributionModel::isInstitution($entity)
        ) {
            $institution = ContributionModel::assert($entity, 'institution');
            $addresses = $this->entityProbeAddresses($institution);

            if ($addresses === []) {
                $addresses = [$this->addressPayload($proposed)];
            }

            return array_map(
                fn (array $address): array => ['name' => $institution->getAttribute('name'), 'address' => $this->institutionClaims->normalizeAddress($address)],
                $addresses,
            );
        }

        $proposedAddress = $this->institutionClaims->normalizeAddress($this->addressPayload($proposed));
        $hasProposedAddress = array_any($proposedAddress, fn ($value) => ! in_array($value, [null, [], ''], true));

        $name = $proposed['name'] ?? null;

        if ((! is_string($name) || trim($name) === '') && $hasProposedAddress && $entity instanceof Model && ContributionModel::isInstitution($entity)) {
            // The update moves the institution without renaming it: check the
            // current name against the new locality.
            $name = $entity->getAttribute('name');
        }

        if ($request->type === ContributionRequestType::Update
            && ! $hasProposedAddress
            && $entity instanceof Model
            && ContributionModel::isInstitution($entity)
            && ($addresses = $this->entityProbeAddresses(ContributionModel::assert($entity, 'institution'))) !== []
        ) {
            return array_map(
                fn (array $address): array => ['name' => $name, 'address' => $this->institutionClaims->normalizeAddress($address)],
                $addresses,
            );
        }

        return [['name' => $name, 'address' => $proposedAddress]];
    }

    /**
     * Raw stored-row address shapes; normalized by the caller with
     * {@see InstitutionClaimGuard::normalizeAddress()}.
     *
     * @param  \App\Models\Institution  $entity
     * @return list<array<string, mixed>>
     */
    private function entityProbeAddresses(Model $entity): array
    {
        $addresses = [];

        foreach ($entity->addresses()->with('areaAssignments')->get() as $address) {
            $assignments = AddressAssignments::forAddress($address);

            $addresses[] = [
                'country_id' => $address->getAttribute('country_id'),
                'state_id' => $address->getAttribute('state_id'),
                'city_id' => $address->getAttribute('city_id'),
                'area_assignments' => $assignments,
                'google_maps_url' => $address->getAttribute('google_maps_url'),
                'google_place_id' => $address->getAttribute('google_place_id'),
                'google_feature_id' => $address->getAttribute('google_feature_id'),
                'google_cid' => $address->getAttribute('google_cid'),
                'google_entity_id' => $address->getAttribute('google_entity_id'),
            ];
        }

        return $addresses;
    }

    /**
     * Re-run the duplicate guard at approval time: the submit-time check may
     * predate a conflicting submission, and two reviewers may approve
     * conflicting requests concurrently. The request excludes its own staged
     * or target entity and its own pending claim so clean approvals pass.
     *
     * @param  \App\Models\ContributionRequest  $request
     * @param  array<string, mixed>  $state
     */
    private function assertNoConflictingInstitutionClaim(Model $request, array $state): void
    {
        $excludeInstitutionId = null;

        if ($request->type === ContributionRequestType::Update
            && is_string($request->entity_id)
            && $request->entity_id !== ''
        ) {
            $excludeInstitutionId = $request->entity_id;
        } elseif ($request->entity instanceof Model && ContributionModel::isInstitution($request->entity)) {
            $excludeInstitutionId = (string) $request->entity->getKey();
        }

        $conflict = $this->institutionClaims->institutionDuplicateExists($state, $excludeInstitutionId, true)
            || $this->institutionClaims->pendingInstitutionClaimExists($state, (string) $request->getKey(), true);

        if ($conflict) {
            throw ValidationException::withMessages([
                'reviewer_note' => __('Cannot approve this request because another institution or pending submission already claims the same Google Maps place or name and locality. Reject the conflicting submission first.'),
            ]);
        }
    }

    /**
     * @param  \App\Models\ContributionRequest  $request
     */
    private function approveCreateRequest(Model $request): Model
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->proposed_data ?? [];

        $entity = $request->entity;

        if ($entity instanceof Model && ContributionModel::isInstitution($entity)) {
            $entity = ContributionModel::assert($entity, 'institution');
            app(InstitutionWorkflow::class)->changeStatus((string) $entity->getKey(), 'verified');
            $this->attachAsOwnerIfSupported($request->proposer, $entity);

            return $entity;
        }

        if ($entity instanceof Model && ContributionModel::isPerson($entity)) {
            $entity = ContributionModel::assert($entity, 'person');
            $this->personWorkflow->changeStatus((string) $entity->getKey(), 'verified');
            $this->attachAsOwnerIfSupported($request->proposer, $entity);

            return $entity;
        }

        if ($entity instanceof Model && ContributionModel::isReference($entity)) {
            $entity = ContributionModel::assert($entity, 'reference');
            $this->referenceWorkflow->changeStatus((string) $entity->getKey(), 'verified');

            return $entity;
        }

        return match ($request->subject_type) {
            ContributionSubjectType::Institution => $this->createInstitutionFromRequest($payload),
            ContributionSubjectType::Person => $this->createPersonFromRequest($request, $payload),
            ContributionSubjectType::Reference => $this->createReferenceFromRequest($payload),
            default => throw new RuntimeException('Unsupported create request subject.'),
        };
    }

    /**
     * @param  \App\Models\ContributionRequest  $request
     */
    private function applyApprovedUpdate(Model $request): void
    {
        $entity = $request->entity;

        if (! $entity instanceof Model) {
            throw new RuntimeException('Update request is missing its target entity.');
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->proposed_data ?? [];
        $dirtyBeforeSave = ContributionModel::isEvent($entity)
            ? $this->eventAuthoring->applyContributionUpdate((string) $entity->getKey(), $payload)
            : $this->applyDirectoryUpdate($entity, $payload);

        if (ContributionModel::isEvent($entity) && $dirtyBeforeSave !== []) {
            $this->eventModeration->handleSensitiveChange((string) $entity->getKey(), $dirtyBeforeSave);
        }

        if (ContributionModel::isEvent($entity)
            || ContributionModel::isInstitution($entity)
            || ContributionModel::isPerson($entity)
            || ContributionModel::isReference($entity)
        ) {
            $this->applyStagedMedia($request, $entity);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyDirectoryUpdate(Model $entity, array $payload): array
    {
        if (ContributionModel::isInstitution($entity)) {
            return $this->institutionWorkflow->applyContribution((string) $entity->getKey(), $payload);
        }

        if (ContributionModel::isPerson($entity)) {
            return $this->personWorkflow->applyContribution((string) $entity->getKey(), $payload);
        }

        if (ContributionModel::isReference($entity)) {
            return $this->referenceWorkflow->applyContribution((string) $entity->getKey(), $payload);
        }

        throw new RuntimeException('Unsupported contribution entity type.');
    }

    /**
     * Apply media that a non-privileged contributor staged on the pending request.
     *
     * @param  \App\Models\ContributionRequest  $request
     */
    private function applyStagedMedia(Model $request, Model $entity): void
    {
        $staged = $request->getMedia('pending_media');

        if ($staged->isEmpty()) {
            return;
        }

        if (ContributionModel::isEvent($entity)) {
            $this->applyEventStagedMedia(ContributionModel::assert($entity, 'event'), $staged);

            return;
        }

        $directoryEntity = match (true) {
            ContributionModel::isInstitution($entity) => ContributionModel::assert($entity, 'institution'),
            ContributionModel::isPerson($entity) => ContributionModel::assert($entity, 'person'),
            default => ContributionModel::assert($entity, 'reference'),
        };

        $this->directoryContributionMedia->applyStagedMedia($directoryEntity, $staged);
    }

    /**
     * Event media stays on the root approval until the Event media slice.
     * The donation-QR branch still hands the operation to Directory.
     *
     * @param  \App\Models\Event  $entity
     * @param  iterable<int, \Spatie\MediaLibrary\MediaCollections\Models\Media>  $staged
     */
    private function applyEventStagedMedia(Model $entity, iterable $staged): void
    {
        foreach ($staged as $media) {
            $field = (string) $media->getCustomProperty('contribution_field', 'cover');

            if ($field === 'donation_qr') {
                $this->directoryContributionMedia->attachStagedDonationQr($entity, $media);

                continue;
            }

            $isSingle = (bool) $media->getCustomProperty('is_single', true);

            if ($isSingle) {
                $entity->clearMediaCollection($field);
            }

            $entity->addMediaFromDisk($media->getPathRelativeToRoot(), $media->disk)
                ->usingFileName($media->file_name)
                ->toMediaCollection($field);

            $this->mediaCollectionSync->deleteMedia($media);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return \App\Models\Institution
     */
    private function createInstitutionFromRequest(array $payload): Model
    {
        $institutionId = $this->institutionWorkflow->createVerifiedFromContribution($payload);

        return ContributionModel::query('institution')->findOrFail($institutionId);
    }

    /**
     * @param  \App\Models\ContributionRequest  $request
     * @param  array<string, mixed>  $payload
     * @return \App\Models\Person
     */
    private function createPersonFromRequest(Model $request, array $payload): Model
    {
        $address = $this->addressPayload($payload);
        $personId = $this->personWorkflow->createVerifiedFromContribution([
            ...$payload,
            'address' => $address,
        ]);
        $person = ContributionModel::query('person')->findOrFail($personId);
        $this->attachAsOwnerIfSupported($request->proposer, $person);

        return $person;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return \App\Models\Reference
     */
    private function createReferenceFromRequest(array $payload): Model
    {
        $referenceId = $this->referenceWorkflow->createVerifiedFromContribution($payload);

        return ContributionModel::query('reference')->withoutOwnerScope()->findOrFail($referenceId);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function addressPayload(array $payload): array
    {
        if (is_array($payload['address'] ?? null) && $payload['address'] !== []) {
            return $payload['address'];
        }

        $addressKeys = [
            'country_id',
            'state_id',
            'city_id',
            'area_assignments',
            'line1',
            'line2',
            'postcode',
            'latitude',
            'longitude',
            'google_maps_url',
            'google_place_id',
            'google_feature_id',
            'google_cid',
            'google_entity_id',
            'waze_url',
        ];

        $address = [];

        foreach ($addressKeys as $key) {
            if (array_key_exists($key, $payload)) {
                $address[$key] = $payload[$key];
            }
        }

        return $address;
    }

    private function attachAsOwnerIfSupported(?Model $user, Model $entity): void
    {
        if (! $user instanceof Model || ContributionModel::isInstitution($entity)) {
            return;
        }

        $this->membershipCommands->assignOwner(
            MembershipSubjectType::Person,
            (string) $entity->getKey(),
            (string) $user->getKey(),
        );
    }
}
