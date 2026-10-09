<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Frontend;

use App\Enums\Rewards\RewardAwardStatus;
use App\Models\RewardAppeal;
use App\Models\RewardAward;
use App\Models\RewardAwardEntry;
use App\Models\RewardProgram;
use App\Support\Api\ApiPagination;
use App\Support\Rewards\RewardMemberPresenter;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[Group('Rewards', 'Member reward program catalog, enrollment, awards, balances, and appeals.')]
class RewardAwardController extends FrontendController
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        $this->authorize('viewAny', RewardAward::class);

        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(array_map(static fn (RewardAwardStatus $status): string => $status->value, RewardAwardStatus::cases()))],
            'program_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $perPage = ApiPagination::normalizePerPage((int) ($validated['per_page'] ?? 20), 20, 50);

        $query = RewardAward::query()
            ->where('recipient_type', $user->getMorphClass())
            ->where('recipient_id', $user->getKey())
            ->orderByDesc('id');

        if (is_string($validated['status'] ?? null)) {
            $query->where('status', $validated['status']);
        }

        if (is_string($validated['program_id'] ?? null)) {
            $query->where('program_id', $validated['program_id']);
        }

        $awards = $query->paginate($perPage);

        $programs = RewardProgram::query()
            ->whereIn('id', $awards->getCollection()->map(fn (RewardAward $award): string => (string) $award->program_id)->unique()->all())
            ->get()
            ->keyBy(fn (RewardProgram $program): string => (string) $program->getKey());

        return response()->json([
            'data' => $awards->getCollection()->map(
                fn (RewardAward $award): array => RewardMemberPresenter::awardSummary(
                    $award,
                    $programs->get((string) $award->program_id),
                )
            )->all(),
            'pagination' => ApiPagination::paginationMeta($awards),
        ]);
    }

    public function show(Request $request, string $award): JsonResponse
    {
        $user = $this->requireUser($request);

        /** @var RewardAward $record */
        $record = RewardAward::query()
            ->where('recipient_type', $user->getMorphClass())
            ->where('recipient_id', $user->getKey())
            ->whereKey($award)
            ->firstOrFail();
        $this->authorize('view', $record);

        $program = RewardProgram::query()->whereKey((string) $record->program_id)->first();

        $entries = RewardAwardEntry::query()
            ->where('award_id', $record->getKey())
            ->orderBy('seq')
            ->get();

        $appeal = RewardAppeal::query()
            ->where('award_id', $record->getKey())
            ->orderByDesc('seq')
            ->first();

        return response()->json([
            'data' => RewardMemberPresenter::awardDetail($record, $program, $entries, $appeal),
        ]);
    }
}
