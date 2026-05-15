<?php

namespace App\Http\Controllers\Api\Frontend;

use App\Data\Api\Frontend\Follow\FollowStateData;
use App\Enums\DawahShareOutcomeType;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Series;
use App\Models\Speaker;
use App\Models\User;
use App\Services\ShareTrackingService;
use App\Support\Models\SlugOrUuidResolver;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Follow', 'Authenticated endpoints for reading and mutating follow state across public institutions, speakers, references, and series.')]
class FollowController extends FrontendController
{
    public function __construct(
        private readonly ShareTrackingService $shareTrackingService,
        private readonly SlugOrUuidResolver $slugOrUuidResolver,
    ) {}

    #[PathParameter('type', 'Followable public resource type. Use singular values only: `institution`, `speaker`, `reference`, or `series`; plural list paths such as `/follows/speakers` are not valid.', example: 'institution')]
    #[PathParameter('subject', 'Public slug or UUID of the followable resource.', example: 'masjid-jamek-kuala-lumpur')]
    #[Endpoint(
        title: 'Get follow state',
        description: 'Returns whether the current authenticated user is following one requested public institution, speaker, reference, or series. The `{subject}` path segment is required; followed directory lists use `/speakers?following=true`, `/institutions?following=true`, or `/references?following=true` instead of plural `/follows/...` routes.',
    )]
    public function show(string $type, string $subject, Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        $record = $this->resolveFollowable($type, $subject, $user);

        return response()->json([
            'data' => $this->followData($record, $user),
            'meta' => [
                'request_id' => $this->requestId($request),
            ],
        ]);
    }

    #[PathParameter('type', 'Followable public resource type. Use singular values only: `institution`, `speaker`, `reference`, or `series`; plural list paths such as `/follows/speakers` are not valid.', example: 'institution')]
    #[PathParameter('subject', 'Public slug or UUID of the followable resource.', example: 'masjid-jamek-kuala-lumpur')]
    #[Endpoint(
        title: 'Follow a resource',
        description: 'Creates a follow relationship for the current authenticated user and one requested public institution, speaker, reference, or series. The `{subject}` path segment is required; followed directory lists use `/speakers?following=true`, `/institutions?following=true`, or `/references?following=true` instead of plural `/follows/...` routes.',
    )]
    public function store(string $type, string $subject, Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        $record = $this->resolveFollowable($type, $subject, $user);

        if (! $user->isFollowing($record)) {
            $user->follow($record);

            $this->shareTrackingService->recordOutcome(
                type: $this->followOutcomeType($record),
                outcomeKey: strtolower(class_basename($record)).'_follow:user:'.$user->getKey().':subject:'.$record->getKey(),
                subject: $record,
                actor: $user,
                request: $request,
                metadata: [
                    'subject_id' => $record->getKey(),
                    'subject_type' => $record->getMorphClass(),
                ],
            );
        }

        return response()->json([
            'data' => $this->followData($record, $user),
            'meta' => [
                'request_id' => $this->requestId($request),
            ],
        ], 201);
    }

    #[PathParameter('type', 'Followable public resource type. Use singular values only: `institution`, `speaker`, `reference`, or `series`; plural list paths such as `/follows/speakers` are not valid.', example: 'institution')]
    #[PathParameter('subject', 'Public slug or UUID of the followable resource.', example: 'masjid-jamek-kuala-lumpur')]
    #[Endpoint(
        title: 'Unfollow a resource',
        description: 'Removes the follow relationship between the current authenticated user and one requested public institution, speaker, reference, or series. The `{subject}` path segment is required; followed directory lists use `/speakers?following=true`, `/institutions?following=true`, or `/references?following=true` instead of plural `/follows/...` routes.',
    )]
    public function destroy(string $type, string $subject, Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        $record = $this->resolveFollowable($type, $subject, $user);

        $user->unfollow($record);

        return response()->json([
            'data' => $this->followData($record, $user),
            'meta' => [
                'request_id' => $this->requestId($request),
            ],
        ]);
    }

    private function resolveFollowable(string $type, string $subject, User $user): Institution|Speaker|Reference|Series
    {
        return match ($type) {
            'institution' => $this->resolveInstitution($subject, $user),
            'speaker' => $this->resolveSpeaker($subject, $user),
            'reference' => $this->resolveReference($subject),
            'series' => $this->resolveSeries($subject, $user),
            default => abort(404),
        };
    }

    private function resolveInstitution(string $subject, User $user): Institution
    {
        /** @var Institution $record */
        $record = $this->slugOrUuidResolver->firstOrFail(
            Institution::query(),
            'institutions.slug',
            $subject,
        );

        if ($record->status !== 'verified' && ! $user->hasAnyRole(['super_admin', 'moderator'])) {
            abort(404);
        }

        return $record;
    }

    private function resolveSpeaker(string $subject, User $user): Speaker
    {
        /** @var Speaker $record */
        $record = $this->slugOrUuidResolver->firstOrFail(
            Speaker::query(),
            'speakers.slug',
            $subject,
        );

        if (! $record->is_active || ($record->status !== 'verified' && ! $user->hasAnyRole(['super_admin', 'moderator']))) {
            abort(404);
        }

        return $record;
    }

    private function resolveReference(string $subject): Reference
    {
        /** @var Reference $record */
        $record = $this->slugOrUuidResolver->firstOrFail(
            Reference::query(),
            'references.slug',
            $subject,
        );

        abort_unless($record->is_active, 404);

        return $record;
    }

    private function resolveSeries(string $subject, User $user): Series
    {
        /** @var Series $record */
        $record = $this->slugOrUuidResolver->firstOrFail(
            Series::query(),
            'series.slug',
            $subject,
        );

        if ($record->visibility !== 'public' && ! $user->hasAnyRole(['super_admin', 'moderator'])) {
            abort(404);
        }

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    private function followData(Institution|Speaker|Reference|Series $record, User $user): array
    {
        return FollowStateData::fromModel($record, $user)->toArray();
    }

    private function followOutcomeType(Institution|Speaker|Reference|Series $record): DawahShareOutcomeType
    {
        return match (true) {
            $record instanceof Institution => DawahShareOutcomeType::InstitutionFollow,
            $record instanceof Speaker => DawahShareOutcomeType::SpeakerFollow,
            $record instanceof Reference => DawahShareOutcomeType::ReferenceFollow,
            default => DawahShareOutcomeType::SeriesFollow,
        };
    }
}
