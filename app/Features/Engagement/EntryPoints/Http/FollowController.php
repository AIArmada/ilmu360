<?php

namespace App\Features\Engagement\EntryPoints\Http;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Features\Engagement\Api\EngagementSubjectType;
use App\Features\Engagement\Api\SubjectFollowCommands;
use App\Features\Engagement\Api\SubjectFollows;
use App\Features\Engagement\EntryPoints\Http\Data\FollowStateData;
use App\Features\Events\Api\SeriesAuthoring;
use App\Features\Institutions\Api\Orm\InstitutionLookup;
use App\Features\Persons\Api\Orm\PersonLookup;
use App\Features\References\Api\ReferenceLookup;
use App\Models\User;
use App\Platform\Http\FrontendController;
use App\Shared\Directory\FollowableSubject;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\PathParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Follow', 'Authenticated endpoints for reading and mutating follow state across public institutions, persons, references, and series.')]
class FollowController extends FrontendController
{
    public function __construct(
        private readonly SubjectFollowCommands $subjectFollowCommands,
        private readonly InstitutionLookup $institutions,
        private readonly PersonLookup $persons,
        private readonly ReferenceLookup $references,
        private readonly SeriesAuthoring $series,
    ) {}

    #[PathParameter('type', 'Followable public resource type. Use singular values only: `institution`, `person`, `reference`, or `series`; plural list paths such as `/follows/persons` are not valid.', example: 'institution')]
    #[PathParameter('subject', 'Public slug or UUID of the followable resource.', example: 'masjid-jamek-kuala-lumpur')]
    #[Endpoint(
        title: 'Get follow state',
        description: 'Returns whether the current authenticated user is following one requested public institution, person, reference, or series. The `{subject}` path segment is required; followed directory lists use `/persons?following=true`, `/institutions?following=true`, or `/references?following=true` instead of plural `/follows/...` routes.',
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

    #[PathParameter('type', 'Followable public resource type. Use singular values only: `institution`, `person`, `reference`, or `series`; plural list paths such as `/follows/persons` are not valid.', example: 'institution')]
    #[PathParameter('subject', 'Public slug or UUID of the followable resource.', example: 'masjid-jamek-kuala-lumpur')]
    #[Endpoint(
        title: 'Follow a resource',
        description: 'Creates a follow relationship for the current authenticated user and one requested public institution, person, reference, or series. The `{subject}` path segment is required; followed directory lists use `/persons?following=true`, `/institutions?following=true`, or `/references?following=true` instead of plural `/follows/...` routes.',
    )]
    public function store(string $type, string $subject, Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        $record = $this->resolveFollowable($type, $subject, $user);

        if (! $this->isFollowing($record, $user)) {
            $this->subjectFollowCommands->follow(
                $record->morphType,
                $record->id,
                (string) $user->getKey(),
                ['subject_id' => $record->id, 'subject_type' => $record->morphType],
            );
        }

        return response()->json([
            'data' => $this->followData($record, $user),
            'meta' => [
                'request_id' => $this->requestId($request),
            ],
        ], 201);
    }

    #[PathParameter('type', 'Followable public resource type. Use singular values only: `institution`, `person`, `reference`, or `series`; plural list paths such as `/follows/persons` are not valid.', example: 'institution')]
    #[PathParameter('subject', 'Public slug or UUID of the followable resource.', example: 'masjid-jamek-kuala-lumpur')]
    #[Endpoint(
        title: 'Unfollow a resource',
        description: 'Removes the follow relationship between the current authenticated user and one requested public institution, person, reference, or series. The `{subject}` path segment is required; followed directory lists use `/persons?following=true`, `/institutions?following=true`, or `/references?following=true` instead of plural `/follows/...` routes.',
    )]
    public function destroy(string $type, string $subject, Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        $record = $this->resolveFollowable($type, $subject, $user);

        $this->subjectFollowCommands->unfollow($record->morphType, $record->id, (string) $user->getKey());

        return response()->json([
            'data' => $this->followData($record, $user),
            'meta' => [
                'request_id' => $this->requestId($request),
            ],
        ]);
    }

    private function resolveFollowable(string $type, string $subject, User $user): FollowableSubject
    {
        $record = match ($type) {
            'institution' => $this->institutions->findFollowableSubject($subject),
            'person' => $this->persons->findFollowableSubject($subject),
            'reference' => $this->references->findFollowableSubject($subject),
            'series' => $this->series->findFollowableSeries($subject),
            default => abort(404),
        };

        if (! $record instanceof FollowableSubject) {
            abort(404);
        }

        if ($type === 'reference') {
            abort_unless($record->isPubliclyVisible, 404);

            return $record;
        }

        if (! $record->isPubliclyVisible && ! $user->hasAnyRole(['super_admin', 'moderator'])) {
            abort(404);
        }

        return $record;
    }

    private function isFollowing(FollowableSubject $subject, User $user): bool
    {
        return OwnerContext::withOwner(null, fn (): bool => app(SubjectFollows::class)
            ->followStateFor(
                (string) $user->getKey(),
                EngagementSubjectType::from($subject->type),
                $subject->id,
            )
            ->isFollowing);
    }

    /**
     * @return array<string, mixed>
     */
    private function followData(FollowableSubject $record, User $user): array
    {
        return FollowStateData::fromSubject($record, $this->isFollowing($record, $user))->toArray();
    }
}
