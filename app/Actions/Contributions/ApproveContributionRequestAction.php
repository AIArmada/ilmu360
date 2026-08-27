<?php

namespace App\Actions\Contributions;

use AIArmada\Membership\Actions\AddMemberAction;
use AIArmada\Membership\Enums\MemberRole;
use App\Actions\Institutions\GenerateInstitutionSlugAction;
use App\Actions\Persons\GeneratePersonSlugAction;
use App\Enums\ContributionRequestStatus;
use App\Enums\ContributionRequestType;
use App\Enums\ContributionSubjectType;
use App\Forms\SharedFormSchema;
use App\Models\ContributionRequest;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\User;
use App\Services\ContributionEntityMutationService;
use App\Services\ModerationService;
use App\Services\Notifications\ContributionRequestNotificationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
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
        private readonly GenerateInstitutionSlugAction $generateInstitutionSlugAction,
        private readonly GeneratePersonSlugAction $generatePersonSlugAction,
    ) {}

    public function handle(ContributionRequest $request, User $reviewer, ?string $reviewerNote = null): ContributionRequest
    {
        if (! $request->isPending()) {
            throw new RuntimeException('Only pending contribution requests can be approved.');
        }

        DB::transaction(function () use ($request, $reviewer, $reviewerNote): void {
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

        $freshRequest = $request->fresh(['entity', 'proposer', 'reviewer']) ?? $request;

        $this->contributionRequestNotificationService->notifyCreateRequestApproved($freshRequest);

        return $freshRequest;
    }

    private function approveCreateRequest(ContributionRequest $request): Institution|Person
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->proposed_data ?? [];

        $entity = $request->entity;

        if ($entity instanceof Institution || $entity instanceof Person) {
            $entity->forceFill([
                'status' => 'verified',
                'verified_at' => now(),
                'last_state_change_at' => now(),
            ])->save();

            $this->attachAsOwnerIfSupported($request->proposer, $entity);

            return $entity;
        }

        return match ($request->subject_type) {
            ContributionSubjectType::Institution => $this->createInstitutionFromRequest($payload),
            ContributionSubjectType::Person => $this->createPersonFromRequest($request, $payload),
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

        if ($entity instanceof Event || $entity instanceof Institution || $entity instanceof Person) {
            $this->applyStagedMedia($request, $entity);
        }
    }

    /**
     * Apply media that a non-privileged contributor staged on the pending request.
     */
    private function applyStagedMedia(ContributionRequest $request, Event|Institution|Person $entity): void
    {
        $staged = $request->getMedia('pending_media');

        if ($staged->isEmpty()) {
            return;
        }

        foreach ($staged as $media) {
            $field = (string) $media->getCustomProperty('contribution_field', 'cover');
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
        $address = $this->addressPayload($payload);

        $institution = Institution::create([
            'name' => (string) ($payload['name'] ?? 'Institution'),
            'slug' => $this->generateInstitutionSlugAction->handle(
                (string) ($payload['name'] ?? 'Institution'),
                $address,
            ),
            'type' => (string) ($payload['type'] ?? 'masjid'),
            'description' => $payload['description'] ?? null,
            'status' => 'verified',
            'verified_at' => now(),
            'last_state_change_at' => now(),
            'allow_public_event_submission' => true,
        ]);

        SharedFormSchema::createAddressFromData($institution, $address, allowCountryOnly: true);
        $this->generateInstitutionSlugAction->syncInstitutionSlug($institution);

        return $institution;
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
     * @return array<string, mixed>
     */
    private function addressPayload(array $payload): array
    {
        if (is_array($payload['address'] ?? null)) {
            return $payload['address'];
        }

        $addressKeys = [
            'country_id',
            'area_assignments',
            'line1',
            'line2',
            'postcode',
            'latitude',
            'longitude',
            'google_maps_url',
            'provider_place_id',
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
