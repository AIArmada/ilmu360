<?php

declare(strict_types=1);

namespace App\Features\Rewards\EntryPoints\Http;

use AIArmada\Rewards\Enums\RewardBadgeStatus;
use AIArmada\Rewards\Models\RewardBadgeGrant;
use AIArmada\Rewards\Models\RewardPointLot;
use AIArmada\Rewards\Support\RewardPointLedger;
use App\Features\Rewards\Support\RewardMemberPresenter;
use App\Platform\Http\FrontendController;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Rewards', 'Member reward program catalog, enrollment, awards, balances, and appeals.')]
class RewardBalanceController extends FrontendController
{
    #[Endpoint(title: 'Get your reward balances')]
    public function balances(Request $request): JsonResponse
    {
        $user = $this->requireAccountActor($request);

        $pairs = RewardPointLot::query()
            ->where('recipient_type', $user->actorType())
            ->where('recipient_id', $user->actorId())
            ->select(['family_key', 'unit'])
            ->distinct()
            ->orderBy('family_key')
            ->orderBy('unit')
            ->get();

        $asOf = CarbonImmutable::now('UTC');
        $data = [];

        foreach ($pairs as $pair) {
            $buckets = RewardPointLedger::balancesFor(
                $user->actorType(),
                (string) $user->actorId(),
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

    #[Endpoint(title: 'List your reward badges')]
    public function badges(Request $request): JsonResponse
    {
        $user = $this->requireAccountActor($request);

        $grants = RewardBadgeGrant::query()
            ->where('recipient_type', $user->actorType())
            ->where('recipient_id', $user->actorId())
            ->where('status', RewardBadgeStatus::Granted->value)
            ->orderByDesc('granted_at')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $grants->map(fn (RewardBadgeGrant $grant): array => RewardMemberPresenter::badgeSummary($grant))->all(),
        ]);
    }
}
