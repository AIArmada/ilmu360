<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Frontend;

use AIArmada\Rewards\Enums\RewardProgramStatus;
use AIArmada\Rewards\Models\RewardEnrollment;
use AIArmada\Rewards\Models\RewardEnrollmentAcceptance;
use AIArmada\Rewards\Models\RewardProgram;
use AIArmada\Rewards\Models\RewardProgramVersion;
use AIArmada\Rewards\Support\RewardVersionResolver;
use App\Support\Api\ApiPagination;
use App\Support\Rewards\RewardMemberPresenter;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Rewards', 'Member reward program catalog, enrollment, awards, balances, and appeals.')]
class RewardProgramController extends FrontendController
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        $this->authorize('viewAny', RewardProgram::class);

        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $perPage = ApiPagination::normalizePerPage((int) ($validated['per_page'] ?? 20), 20, 50);

        $programs = RewardProgram::query()
            ->where('status', RewardProgramStatus::Active->value)
            ->orderBy('title')
            ->paginate($perPage);

        $enrollments = RewardEnrollment::query()
            ->where('member_type', $user->getMorphClass())
            ->where('member_id', $user->getKey())
            ->whereIn('program_id', $programs->getCollection()->map(fn (RewardProgram $program): string => (string) $program->getKey())->all())
            ->get()
            ->keyBy(fn (RewardEnrollment $enrollment): string => (string) $enrollment->program_id);

        return response()->json([
            'data' => $programs->getCollection()->map(
                fn (RewardProgram $program): array => RewardMemberPresenter::programSummary(
                    $program,
                    $enrollments->get((string) $program->getKey()),
                )
            )->all(),
            'pagination' => ApiPagination::paginationMeta($programs),
        ]);
    }

    public function show(Request $request, string $program): JsonResponse
    {
        $user = $this->requireUser($request);

        /** @var RewardProgram $record */
        $record = RewardProgram::query()->whereKey($program)->firstOrFail();
        $this->authorize('view', $record);

        $enrollment = RewardEnrollment::query()
            ->where('program_id', $record->getKey())
            ->where('member_type', $user->getMorphClass())
            ->where('member_id', $user->getKey())
            ->first();

        $now = CarbonImmutable::now('UTC');
        $versions = RewardProgramVersion::query()
            ->where('program_id', $record->getKey())
            ->orderBy('version')
            ->get();

        $timeline = [];

        foreach ($versions as $version) {
            $timeline[] = [
                'id' => (string) $version->getKey(),
                'version' => (int) $version->version,
                'effective_from' => CarbonImmutable::parse($version->effective_from)->setTimezone('UTC'),
            ];
        }

        $applicable = RewardVersionResolver::resolveAt($timeline, $now);
        $resolved = null;

        if ($applicable !== null) {
            foreach ($versions as $version) {
                if ((string) $version->getKey() === $applicable['id']) {
                    $resolved = $version;
                    break;
                }
            }
        }

        $acceptance = $enrollment instanceof RewardEnrollment
            ? RewardEnrollmentAcceptance::query()
                ->where('enrollment_id', $enrollment->getKey())
                ->orderByDesc('accepted_at')
                ->orderByDesc('seq')
                ->first()
            : null;

        return response()->json([
            'data' => RewardMemberPresenter::programDetail($record, $resolved, $enrollment, $acceptance),
        ]);
    }
}
