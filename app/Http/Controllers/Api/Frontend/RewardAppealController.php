<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Frontend;

use App\Actions\Rewards\OpenRewardAppealAction;
use App\Models\RewardAppeal;
use App\Models\RewardAppealDecision;
use App\Models\RewardAward;
use App\Support\Api\ApiPagination;
use App\Support\Rewards\RewardMemberPresenter;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

#[Group('Rewards', 'Member reward program catalog, enrollment, awards, balances, and appeals.')]
class RewardAppealController extends FrontendController
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        $this->authorize('viewAny', RewardAppeal::class);

        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $perPage = ApiPagination::normalizePerPage((int) ($validated['per_page'] ?? 20), 20, 50);

        $awardIds = RewardAward::query()
            ->where('recipient_type', $user->getMorphClass())
            ->where('recipient_id', $user->getKey())
            ->pluck('id');

        $appeals = RewardAppeal::query()
            ->whereIn('award_id', $awardIds->all())
            ->orderByDesc('opened_at')
            ->paginate($perPage);

        return response()->json([
            'data' => $appeals->getCollection()->map(
                fn (RewardAppeal $appeal): array => RewardMemberPresenter::appealSummary($appeal)
            )->all(),
            'pagination' => ApiPagination::paginationMeta($appeals),
        ]);
    }

    public function store(Request $request, string $award): JsonResponse
    {
        $user = $this->requireUser($request);

        /** @var RewardAward $record */
        $record = RewardAward::query()
            ->where('recipient_type', $user->getMorphClass())
            ->where('recipient_id', $user->getKey())
            ->whereKey($award)
            ->firstOrFail();
        $this->authorize('openAppeal', $record);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $appeal = OpenRewardAppealAction::run(
                $record,
                'user',
                (string) $user->getKey(),
                'member',
                $validated['reason'],
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'data' => RewardMemberPresenter::appealSummary($appeal),
        ], 201);
    }

    public function show(Request $request, string $appeal): JsonResponse
    {
        $user = $this->requireUser($request);

        /** @var RewardAppeal $record */
        $record = RewardAppeal::query()->whereKey($appeal)->firstOrFail();
        $this->authorize('view', $record);

        // Belt-and-suspenders: members reach only appeals on their own awards.
        $award = RewardAward::query()->whereKey((string) $record->award_id)->first();

        if (! $award instanceof RewardAward
            || (string) $award->recipient_type !== $user->getMorphClass()
            || (string) $award->recipient_id !== (string) $user->getKey()
        ) {
            abort(404);
        }

        $decisions = RewardAppealDecision::query()
            ->where('appeal_id', $record->getKey())
            ->orderBy('seq')
            ->get();

        return response()->json([
            'data' => RewardMemberPresenter::appealDetail($record, $decisions),
        ]);
    }
}
