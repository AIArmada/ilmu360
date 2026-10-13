<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Frontend;

use AIArmada\Rewards\Enums\RewardBadgeStatus;
use AIArmada\Rewards\Models\RewardBadgeGrant;
use AIArmada\Rewards\Models\RewardPointLot;
use AIArmada\Rewards\Support\RewardPointLedger;
use App\Support\Rewards\RewardMemberPresenter;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Rewards', 'Member reward program catalog, enrollment, awards, balances, and appeals.')]
class RewardBalanceController extends FrontendController
{
    public function balances(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);

        $pairs = RewardPointLot::query()
            ->where('recipient_type', $user->getMorphClass())
            ->where('recipient_id', $user->getKey())
            ->select(['family_key', 'unit'])
            ->distinct()
            ->orderBy('family_key')
            ->orderBy('unit')
            ->get();

        $asOf = CarbonImmutable::now('UTC');
        $data = [];

        foreach ($pairs as $pair) {
            $buckets = RewardPointLedger::balancesFor(
                $user->getMorphClass(),
                (string) $user->getKey(),
                (string) $pair->family_key,
                (string) $pair->unit,
                $asOf,
            );

            $data[] = [
                'family_key' => (string) $pair->family_key,
                'unit' => (string) $pair->unit,
                'buckets' => $buckets->toArray(),
                'as_of' => $asOf->toIso8601String(),
            ];
        }

        return response()->json(['data' => $data]);
    }

    public function badges(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);

        $grants = RewardBadgeGrant::query()
            ->where('recipient_type', $user->getMorphClass())
            ->where('recipient_id', $user->getKey())
            ->where('status', RewardBadgeStatus::Granted->value)
            ->orderByDesc('granted_at')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $grants->map(fn (RewardBadgeGrant $grant): array => RewardMemberPresenter::badgeSummary($grant))->all(),
        ]);
    }
}
