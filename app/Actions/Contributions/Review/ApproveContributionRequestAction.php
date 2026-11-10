<?php

namespace App\Actions\Contributions\Review;

use AIArmada\Membership\Actions\AddMemberAction;
use AIArmada\Membership\Enums\MemberRole;
use App\Actions\Contributions\Submission\EnsureUniqueContributionCreateAction;
use App\Actions\Institutions\WithInstitutionMapsIdentityLockAction;
use App\Actions\Persons\GeneratePersonSlugAction;
use App\Actions\References\SaveReferenceAction;
use App\Enums\ContributionRequestStatus;
use App\Enums\ContributionRequestType;
use App\Enums\ContributionSubjectType;
use App\Exceptions\ContributionRequestAlreadyReviewedException;
use App\Exceptions\ContributionRequestNotPendingException;
use App\Features\Directory\Api\InstitutionWorkflow;
use App\Forms\SharedFormSchema;
use App\Models\ContributionRequest;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\User;
use App\Platform\Location\AddressAssignments;
use App\Services\ContributionEntityMutationService;
use App\Services\ModerationService;
use App\Services\Notifications\ContributionRequestNotificationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class ApproveContributionRequestAction
{
    use AsAction;

    public function __construct(
        private readonly ModerationService $moderationService,
        private readonly ContributionEntityMutationService $entityMutationService,
        private readonly ContributionRequestNotificationService $contributionRequestNotificationService,
        private readonly AddMemberAction $addMemberAction,
        private readonly InstitutionWorkflow $institutionWorkflow,
        private readonly GeneratePersonSlugAction $generatePersonSlugAction,
        private readonly SaveReferenceAction $saveReferenceAction,
        private readonly EnsureUniqueContributionCreateAction $ensureUniqueContributionCreateAction,
        private readonly WithInstitutionMapsIdentityLockAction $withInstitutionMapsIdentityLockAction,
    ) {}

    public function handle(ContributionRequest $request, User $reviewer, ?string $reviewerNote = null): ContributionRequest
    {
        if (! $request->isPending()) {
            throw new ContributionRequestNotPendingException('Only pending contribution requests can be approved.');
        }

        $approve = function () use ($request, $reviewer, $reviewerNote): void {
            DB::transaction(function () use ($request, $reviewer, $reviewerNote): void {
                ContributionRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
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
            $this->withInstitutionMapsIdentityLockAction->handle(
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

        $this->contributionRequestNotificationService->notifyCreateRequestApproved($freshRequest);

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
     * @param  array<string, mixed>  $proposed
     * @return list<array{name: mixed, address: array<string, mixed>}>
     */
    private function conflictingClaimProbes(ContributionRequest $request, array $proposed): array
    {
        $entity = $request->entity;

        if ($request->type === ContributionRequestType::Create && $entity instanceof Institution) {
            $addresses = $this->entityProbeAddresses($entity);

            if ($addresses === []) {
                $addresses = [$this->addressPayload($proposed)];
            }

            return array_map(
                static fn (array $address): array => ['name' => $entity->getAttribute('name'), 'address' => EnsureUniqueContributionCreateAction::normalizeClaimAddress($address)],
                $addresses,
            );
        }

        $proposedAddress = EnsureUniqueContributionCreateAction::normalizeClaimAddress($this->addressPayload($proposed));
        $hasProposedAddress = false;

        foreach ($proposedAddress as $value) {
            if ($value !== null && $value !== [] && $value !== '') {
                $hasProposedAddress = true;

                break;
            }
        }

        $name = $proposed['name'] ?? null;

        if ((! is_string($name) || trim($name) === '') && $hasProposedAddress && $entity instanceof Institution) {
            // The update moves the institution without renaming it: check the
            // current name against the new locality.
            $name = $entity->getAttribute('name');
        }

        if ($request->type === ContributionRequestType::Update
            && ! $hasProposedAddress
            && $entity instanceof Institution
            && ($addresses = $this->entityProbeAddresses($entity)) !== []
        ) {
            return array_map(
                static fn (array $address): array => ['name' => $name, 'address' => EnsureUniqueContributionCreateAction::normalizeClaimAddress($address)],
                $addresses,
            );
        }

        return [['name' => $name, 'address' => $proposedAddress]];
    }

    /**
     * Raw stored-row address shapes; normalized by the caller with
     * {@see EnsureUniqueContributionCreateAction::normalizeClaimAddress()}.
     *
     * @return list<array<string, mixed>>
     */
    private function entityProbeAddresses(Institution $entity): array
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
     * @param  array<string, mixed>  $state
     */
    private function assertNoConflictingInstitutionClaim(ContributionRequest $request, array $state): void
    {
        $excludeInstitutionId = null;

        if ($request->type === ContributionRequestType::Update
            && is_string($request->entity_id)
            && $request->entity_id !== ''
        ) {
            $excludeInstitutionId = $request->entity_id;
        } elseif ($request->entity instanceof Institution) {
            $excludeInstitutionId = (string) $request->entity->getKey();
        }

        $conflict = $this->ensureUniqueContributionCreateAction->institutionDuplicateExists($state, $excludeInstitutionId, true)
            || $this->ensureUniqueContributionCreateAction->pendingInstitutionClaimExists($state, (string) $request->getKey(), true);

        if ($conflict) {
            throw ValidationException::withMessages([
                'reviewer_note' => __('Cannot approve this request because another institution or pending submission already claims the same Google Maps place or name and locality. Reject the conflicting submission first.'),
            ]);
        }
    }

    private function approveCreateRequest(ContributionRequest $request): Institution|Person|Reference
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->proposed_data ?? [];

        $entity = $request->entity;

        if ($entity instanceof Institution) {
            app(InstitutionWorkflow::class)->changeStatus((string) $entity->getKey(), 'verified');
            $this->attachAsOwnerIfSupported($request->proposer, $entity);

            return $entity;
        }

        if ($entity instanceof Person || $entity instanceof Reference) {
            $entity->forceFill([
                'status' => 'verified',
                'verified_at' => now(),
                'last_state_change_at' => now(),
            ])->save();

            if (! $entity instanceof Reference) {
                $this->attachAsOwnerIfSupported($request->proposer, $entity);
            }

            return $entity;
        }

        return match ($request->subject_type) {
            ContributionSubjectType::Institution => $this->createInstitutionFromRequest($payload),
            ContributionSubjectType::Person => $this->createPersonFromRequest($request, $payload),
            ContributionSubjectType::Reference => $this->createReferenceFromRequest($payload),
            default => throw new RuntimeException('Unsupported create request subject.'),
        };
    }

    private function applyApprovedUpdate(ContributionRequest $request): void
    {
        $entity = $request->entity;

        if (! $entity instanceof Model) {
            throw new RuntimeException('Update request is missing its target entity.');
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->proposed_data ?? [];
        $dirtyBeforeSave = $this->entityMutationService->apply($entity, $payload);

        if ($entity instanceof Event && $dirtyBeforeSave !== []) {
            $this->moderationService->handleSensitiveChange($entity, $dirtyBeforeSave);
        }

        if ($entity instanceof Event || $entity instanceof Institution || $entity instanceof Person || $entity instanceof Reference) {
            $this->applyStagedMedia($request, $entity);
        }
    }

    /**
     * Apply media that a non-privileged contributor staged on the pending request.
     */
    private function applyStagedMedia(ContributionRequest $request, Event|Institution|Person|Reference $entity): void
    {
        $staged = $request->getMedia('pending_media');

        if ($staged->isEmpty()) {
            return;
        }

        foreach ($staged as $media) {
            $field = (string) $media->getCustomProperty('contribution_field', 'cover');

            if ($field === 'donation_qr') {
                $this->entityMutationService->attachStagedDonationQr($entity, $media);

                continue;
            }

            $isSingle = (bool) $media->getCustomProperty('is_single', true);

            if ($isSingle) {
                $entity->clearMediaCollection($field);
            }

            $entity->addMediaFromDisk($media->getPathRelativeToRoot(), $media->disk)
                ->usingFileName($media->file_name)
                ->toMediaCollection($field);

            $media->delete();
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createInstitutionFromRequest(array $payload): Institution
    {
        $institutionId = $this->institutionWorkflow->createVerifiedFromContribution($payload);

        return Institution::query()->findOrFail($institutionId);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createPersonFromRequest(ContributionRequest $request, array $payload): Person
    {
        $address = $this->addressPayload($payload);

        $person = Person::create([
            'name' => (string) ($payload['name'] ?? 'Person'),
            'middle_name' => $payload['middle_name'] ?? null,
            'family_name' => $payload['family_name'] ?? null,
            'gender' => (string) ($payload['gender'] ?? 'male'),
            'bio' => $payload['bio'] ?? null,
            'slug' => $this->generatePersonSlugAction->handle((string) ($payload['name'] ?? 'Person'), $payload),
            'status' => 'verified',
            'verified_at' => now(),
            'last_state_change_at' => now(),
            'allow_public_event_submission' => true,
        ]);

        SharedFormSchema::createAddressFromData($person, $address, allowCountryOnly: true);
        $this->attachAsOwnerIfSupported($request->proposer, $person);

        return $person;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createReferenceFromRequest(array $payload): Reference
    {
        return $this->saveReferenceAction->handle([
            ...$payload,
            'status' => 'verified',
        ]);
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

    private function attachAsOwnerIfSupported(?User $user, Institution|Person $entity): void
    {
        if (! $user instanceof User || $entity instanceof Institution) {
            return;
        }

        $this->addMemberAction->handle($entity, $user, MemberRole::Owner);
    }
}
