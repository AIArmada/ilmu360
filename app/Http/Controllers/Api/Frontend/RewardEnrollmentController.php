<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Frontend;

use AIArmada\Rewards\Models\RewardEnrollment;
use AIArmada\Rewards\Models\RewardProgram;
use App\Features\Rewards\Api\MemberEnrollment;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

#[Group('Rewards', 'Member reward program catalog, enrollment, awards, balances, and appeals.')]
class RewardEnrollmentController extends FrontendController
{
    #[Endpoint(title: 'Enroll in a reward program')]
    public function store(Request $request, string $program): JsonResponse
    {
        $user = $this->requireUser($request);

        /** @var RewardProgram $record */
        $record = RewardProgram::query()->whereKey($program)->firstOrFail();
        $this->authorize('enroll', $record);

        $validated = $request->validate([
            'version_id' => ['required', 'uuid'],
            'accepted_via' => ['nullable', 'string'],
        ]);

        try {
            $snapshot = app(MemberEnrollment::class)->enroll(
                (string) $record->getKey(),
                (string) $user->getKey(),
                $user->getMorphClass(),
                $validated['version_id'],
                'api',
                false,
                $validated['accepted_via'] ?? 'api',
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        if ($snapshot === null) {
            return response()->json(['message' => 'This program has no published terms yet.'], 422);
        }

        return response()->json([
            'data' => [
                'id' => $snapshot->enrollmentId,
                'program_id' => $snapshot->programId,
                'status' => $snapshot->status,
            ],
        ], 201);
    }

    #[Endpoint(title: 'Withdraw from a reward program')]
    public function destroy(Request $request, string $program): JsonResponse
    {
        $user = $this->requireUser($request);

        /** @var RewardProgram $record */
        $record = RewardProgram::query()->whereKey($program)->firstOrFail();
        $this->authorize('leave', $record);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:191'],
        ]);

        try {
            app(MemberEnrollment::class)->leave(
                (string) $record->getKey(),
                (string) $user->getKey(),
                $user->getMorphClass(),
                $validated['reason'] ?? null,
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        /** @var RewardEnrollment $enrollment */
        $enrollment = RewardEnrollment::query()
            ->where('program_id', $record->getKey())
            ->where('member_type', $user->getMorphClass())
            ->where('member_id', $user->getKey())
            ->firstOrFail();

        return response()->json([
            'data' => [
                'id' => (string) $enrollment->getKey(),
                'program_id' => (string) $record->getKey(),
                'status' => $enrollment->status->value,
            ],
        ]);
    }

    #[Endpoint(title: 'Accept an updated reward program version')]
    public function reaccept(Request $request, string $program): JsonResponse
    {
        $user = $this->requireUser($request);

        /** @var RewardProgram $record */
        $record = RewardProgram::query()->whereKey($program)->firstOrFail();
        $this->authorize('leave', $record);

        $validated = $request->validate([
            'version_id' => ['required', 'uuid'],
            'accepted_via' => ['nullable', 'string'],
        ]);

        try {
            $snapshot = app(MemberEnrollment::class)->reaccept(
                (string) $record->getKey(),
                (string) $user->getKey(),
                $user->getMorphClass(),
                $validated['version_id'],
                $validated['accepted_via'] ?? 'api',
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        if ($snapshot === null) {
            return response()->json(['message' => 'This program has no published terms yet.'], 422);
        }

        return response()->json([
            'data' => [
                'id' => $snapshot->acceptanceId,
                'enrollment_id' => $snapshot->enrollmentId,
                'material_terms_hash' => (string) $snapshot->materialTermsHash,
            ],
        ], 201);
    }
}
