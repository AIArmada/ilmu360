<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Frontend;

use AIArmada\Rewards\Actions\RegisterRewardPayeeAction;
use AIArmada\Rewards\Models\RewardPayee;
use App\Support\Api\ApiPagination;
use App\Support\Rewards\RewardMemberPresenter;
use App\Support\Rewards\RewardPayoutMethods;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

#[Group('Rewards', 'Member payout destinations. Registration lands pending for independent staff verification; reads are masked.')]
class RewardPayeeController extends FrontendController
{
    public function methods(): JsonResponse
    {
        $options = [];

        foreach (RewardPayoutMethods::options() as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }

        return response()->json(['data' => $options]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        $this->authorize('viewAny', RewardPayee::class);

        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $perPage = ApiPagination::normalizePerPage((int) ($validated['per_page'] ?? 20), 20, 50);

        $payees = RewardPayee::query()
            ->where('recipient_type', $user->getMorphClass())
            ->where('recipient_id', $user->getKey())
            ->orderByDesc('verified_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $payees->getCollection()->map(
                fn (RewardPayee $payee): array => RewardMemberPresenter::payeeSummary($payee)
            )->all(),
            'pagination' => ApiPagination::paginationMeta($payees),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        $this->authorize('register', RewardPayee::class);

        $validated = $request->validate([
            'method' => ['required', 'string', 'max:32'],
            'account_ref' => ['required', 'string', 'max:191'],
            'account_display' => ['required', 'string', 'max:191'],
        ]);

        try {
            $payee = RegisterRewardPayeeAction::run(
                $user->getMorphClass(),
                (string) $user->getKey(),
                $validated['method'],
                $validated['account_ref'],
                $validated['account_display'],
                'user',
                (string) $user->getKey(),
                'member',
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'data' => RewardMemberPresenter::payeeSummary($payee),
        ], 201);
    }
}
