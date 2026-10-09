<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Frontend;

use App\Actions\Rewards\EnrollRewardMemberAction;
use App\Actions\Rewards\LeaveRewardProgramAction;
use App\Actions\Rewards\ReacceptRewardTermsAction;
use App\Models\RewardEnrollment;
use App\Models\RewardProgram;
use App\Models\RewardProgramVersion;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

#[Group('Rewards', 'Member reward program catalog, enrollment, awards, balances, and appeals.')]
class RewardEnrollmentController extends FrontendController
{
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

        /** @var RewardProgramVersion $version */
        $version = RewardProgramVersion::query()
            ->where('program_id', $record->getKey())
            ->whereKey($validated['version_id'])
            ->firstOrFail();

        try {
            $enrollment = EnrollRewardMemberAction::run(
                $record,
                'user',
                (string) $user->getKey(),
                $version,
                $validated['accepted_via'] ?? 'api',
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'data' => [
                'id' => (string) $enrollment->getKey(),
                'program_id' => (string) $record->getKey(),
                'status' => $enrollment->status->value,
            ],
        ], 201);
    }

    public function destroy(Request $request, string $program): JsonResponse
    {
        $user = $this->requireUser($request);

        /** @var RewardProgram $record */
        $record = RewardProgram::query()->whereKey($program)->firstOrFail();
        $this->authorize('leave', $record);

        $enrollment = RewardEnrollment::query()
            ->where('program_id', $record->getKey())
            ->where('member_type', $user->getMorphClass())
            ->where('member_id', $user->getKey())
            ->firstOrFail();

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:191'],
        ]);

        try {
            $left = LeaveRewardProgramAction::run($enrollment, $validated['reason'] ?? null);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'data' => [
                'id' => (string) $left->getKey(),
                'program_id' => (string) $record->getKey(),
                'status' => $left->status->value,
            ],
        ]);
    }

    public function reaccept(Request $request, string $program): JsonResponse
    {
        $user = $this->requireUser($request);

        /** @var RewardProgram $record */
        $record = RewardProgram::query()->whereKey($program)->firstOrFail();
        $this->authorize('leave', $record);

        $enrollment = RewardEnrollment::query()
            ->where('program_id', $record->getKey())
            ->where('member_type', $user->getMorphClass())
            ->where('member_id', $user->getKey())
            ->firstOrFail();

        $validated = $request->validate([
            'version_id' => ['required', 'uuid'],
            'accepted_via' => ['nullable', 'string'],
        ]);

        /** @var RewardProgramVersion $version */
        $version = RewardProgramVersion::query()
            ->where('program_id', $record->getKey())
            ->whereKey($validated['version_id'])
            ->firstOrFail();

        try {
            $acceptance = ReacceptRewardTermsAction::run(
                $enrollment,
                $version,
                $validated['accepted_via'] ?? 'api',
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'data' => [
                'id' => (string) $acceptance->getKey(),
                'enrollment_id' => (string) $enrollment->getKey(),
                'material_terms_hash' => (string) $acceptance->material_terms_hash,
            ],
        ], 201);
    }
}
