<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Frontend;

use App\Models\RewardAward;
use App\Models\RewardPayoutRequest;
use App\Support\Api\ApiPagination;
use App\Support\Rewards\RewardMemberPresenter;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Rewards', 'Member payout status for own cash awards. Payee data is minimized to the masked display value.')]
class RewardPayoutController extends FrontendController
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        $this->authorize('viewAny', RewardPayoutRequest::class);

        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $perPage = ApiPagination::normalizePerPage((int) ($validated['per_page'] ?? 20), 20, 50);

        $awardIds = RewardAward::query()
            ->where('recipient_type', $user->getMorphClass())
            ->where('recipient_id', $user->getKey())
            ->pluck('id');

        $payouts = RewardPayoutRequest::query()
            ->whereIn('award_id', $awardIds->all())
            ->orderByDesc('requested_at')
            ->paginate($perPage);

        return response()->json([
            'data' => $payouts->getCollection()->map(
                fn (RewardPayoutRequest $payout): array => $this->statementRow($payout)
            )->all(),
            'pagination' => ApiPagination::paginationMeta($payouts),
        ]);
    }

    public function show(Request $request, string $award): JsonResponse
    {
        $user = $this->requireUser($request);

        $record = RewardAward::query()
            ->where('recipient_type', $user->getMorphClass())
            ->where('recipient_id', $user->getKey())
            ->whereKey($award)
            ->firstOrFail();
        $this->authorize('view', $record);

        $payout = RewardPayoutRequest::query()->where('award_id', $record->getKey())->first();

        if (! $payout instanceof RewardPayoutRequest) {
            return response()->json(['data' => null]);
        }

        $this->authorize('view', $payout);

        $snapshot = $payout->payee_snapshot;
        $confirmedAt = $payout->confirmed_at?->toIso8601String();

        return response()->json(['data' => [
            'award_id' => (string) $payout->award_id,
            'status' => $payout->status->value,
            'funds' => RewardMemberPresenter::payoutFundsState($payout->status->value, $confirmedAt),
            'currency' => (string) $payout->currency,
            'amount_minor' => (int) $payout->amount_minor,
            'fee_minor' => (int) $payout->fee_minor,
            'fee_payer' => (string) $payout->fee_payer,
            'processor' => (string) $payout->processor,
            'first_reference' => (string) $payout->first_reference,
            'account_display' => $snapshot['account_display'] ?? null,
            'requested_at' => $payout->requested_at?->toIso8601String(),
            'approved_at' => $payout->approved_at?->toIso8601String(),
            'submitted_at' => $payout->submitted_at?->toIso8601String(),
            'confirmed_at' => $payout->confirmed_at?->toIso8601String(),
            'failed_at' => $payout->failed_at?->toIso8601String(),
            'cancelled_at' => $payout->cancelled_at?->toIso8601String(),
        ]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function statementRow(RewardPayoutRequest $payout): array
    {
        $confirmedAt = $payout->confirmed_at?->toIso8601String();
        $snapshot = $payout->payee_snapshot;

        return [
            'award_id' => (string) $payout->award_id,
            'status' => $payout->status->value,
            'funds' => RewardMemberPresenter::payoutFundsState($payout->status->value, $confirmedAt),
            'currency' => (string) $payout->currency,
            'amount_minor' => (int) $payout->amount_minor,
            'fee_minor' => (int) $payout->fee_minor,
            'processor' => (string) $payout->processor,
            'account_display' => $snapshot['account_display'] ?? null,
            'requested_at' => $payout->requested_at?->toIso8601String(),
            'confirmed_at' => $confirmedAt,
        ];
    }
}
