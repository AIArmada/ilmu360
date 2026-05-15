<?php

namespace App\Actions\Contributions;

use App\Actions\Institutions\GenerateInstitutionSlugAction;
use App\Actions\Membership\AssignOwnerToNewSubject;
use App\Actions\Speakers\GenerateSpeakerSlugAction;
use App\Enums\ContributionRequestStatus;
use App\Enums\ContributionRequestType;
use App\Enums\ContributionSubjectType;
use App\Forms\SharedFormSchema;
use App\Models\ContributionRequest;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Speaker;
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
        private readonly AssignOwnerToNewSubject $assignOwnerToNewSubject,
        private readonly GenerateInstitutionSlugAction $generateInstitutionSlugAction,
        private readonly GenerateSpeakerSlugAction $generateSpeakerSlugAction,
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

            $request->forceFill([
                'reviewer_id' => $reviewer->getKey(),
                'reviewer_note' => $reviewerNote,
                'status' => ContributionRequestStatus::Approved,
                'reviewed_at' => now(),
            ])->save();
        });

        $freshRequest = $request->fresh(['entity', 'proposer', 'reviewer']) ?? $request;

        $this->contributionRequestNotificationService->notifyCreateRequestApproved($freshRequest);

        return $freshRequest;
    }

    private function approveCreateRequest(ContributionRequest $request): Institution|Speaker
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->proposed_data ?? [];

        $entity = $request->entity;

        if ($entity instanceof Institution || $entity instanceof Speaker) {
            $entity->forceFill([
                'status' => 'verified',
                'is_active' => true,
            ])->save();

            $this->attachAsOwnerIfSupported($request->proposer, $entity);

            return $entity;
        }

        return match ($request->subject_type) {
            ContributionSubjectType::Institution => $this->createInstitutionFromRequest($payload),
            ContributionSubjectType::Speaker => $this->createSpeakerFromRequest($request, $payload),
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
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createInstitutionFromRequest(array $payload): Institution
    {
        $address = $this->addressPayload($payload);

        $institution = Institution::create([
            'name' => (string) ($payload['name'] ?? 'Institution'),
            'nickname' => is_string($payload['nickname'] ?? null) && trim($payload['nickname']) !== ''
                ? trim($payload['nickname'])
                : null,
            'slug' => $this->generateInstitutionSlugAction->handle(
                (string) ($payload['name'] ?? 'Institution'),
                $address,
            ),
            'type' => (string) ($payload['type'] ?? 'masjid'),
            'description' => $payload['description'] ?? null,
            'status' => 'verified',
            'is_active' => true,
            'allow_public_event_submission' => true,
        ]);

        SharedFormSchema::createAddressFromData($institution, $address, allowCountryOnly: true);
        $this->generateInstitutionSlugAction->syncInstitutionSlug($institution);

        return $institution;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createSpeakerFromRequest(ContributionRequest $request, array $payload): Speaker
    {
        $address = $this->addressPayload($payload);

        $speaker = Speaker::create([
            'name' => (string) ($payload['name'] ?? 'Speaker'),
            'gender' => (string) ($payload['gender'] ?? 'male'),
            'honorific' => $payload['honorific'] ?? null,
            'pre_nominal' => $payload['pre_nominal'] ?? null,
            'post_nominal' => $payload['post_nominal'] ?? null,
            'job_title' => $payload['job_title'] ?? null,
            'bio' => $payload['bio'] ?? null,
            'is_freelance' => (bool) ($payload['is_freelance'] ?? false),
            'slug' => $this->generateSpeakerSlugAction->handle((string) ($payload['name'] ?? 'Speaker'), $payload),
            'status' => 'verified',
            'is_active' => true,
            'allow_public_event_submission' => true,
        ]);

        SharedFormSchema::createAddressFromData($speaker, $address, allowCountryOnly: true);
        $this->attachAsOwnerIfSupported($request->proposer, $speaker);

        return $speaker;
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
            'state_id',
            'district_id',
            'subdistrict_id',
            'line1',
            'line2',
            'postcode',
            'lat',
            'lng',
            'google_maps_url',
            'google_place_id',
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

    private function attachAsOwnerIfSupported(?User $user, Institution|Speaker $entity): void
    {
        if (! $user instanceof User || $entity instanceof Institution) {
            return;
        }

        $this->assignOwnerToNewSubject->handle($entity, $user);
    }
}
