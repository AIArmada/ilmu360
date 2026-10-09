<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Frontend;

use App\Actions\Rewards\CancelRewardBenefitGrantAction;
use App\Actions\Rewards\ConsumeRewardBenefitAction;
use App\Actions\Rewards\RedeemRewardBenefitAction;
use App\Enums\Rewards\RewardBenefitGrantStatus;
use App\Enums\Rewards\RewardBenefitItemStatus;
use App\Models\RewardBenefitGrant;
use App\Models\RewardBenefitItem;
use App\Support\Api\ApiPagination;
use App\Support\Rewards\RewardBenefitStockLedger;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

#[Group('Rewards', 'Member benefit catalog, redemption, grants, and consumption.')]
class RewardBenefitController extends FrontendController
{
    public function catalog(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        $this->authorize('viewAny', RewardBenefitItem::class);

        $validated = $request->validate([
            'program_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $perPage = ApiPagination::normalizePerPage((int) ($validated['per_page'] ?? 20), 20, 50);

        $query = RewardBenefitItem::query()
            ->where('status', RewardBenefitItemStatus::Active->value)
            ->orderBy('title');

        if (is_string($validated['program_id'] ?? null)) {
            $query->where('program_id', $validated['program_id']);
        }

        $items = $query->paginate($perPage);

        return response()->json([
            'data' => $items->getCollection()->map(static fn (RewardBenefitItem $item): array => [
                'id' => (string) $item->getKey(),
                'program_id' => (string) $item->program_id,
                'benefit_key' => (string) $item->benefit_key,
                'title' => (string) $item->title,
                'conditions' => $item->conditions,
                'cost_points' => $item->cost_points,
                'cost_unit' => $item->cost_unit,
                'direct_entitlement' => (bool) $item->direct_entitlement,
                'available_units' => RewardBenefitStockLedger::balances($item)['available'],
                'expires_at' => $item->expires_at?->toIso8601String(),
            ])->all(),
            'meta' => ApiPagination::paginationMeta($items),
        ]);
    }

    public function redeem(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);

        $validated = $request->validate([
            'item_id' => ['required', 'uuid'],
            'family_key' => ['required', 'string', 'max:64'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'redemption_key' => ['required', 'string', 'max:120'],
            'adapter' => ['nullable', 'string', Rule::in(RedeemRewardBenefitAction::ADAPTERS)],
        ]);

        $item = RewardBenefitItem::query()->whereKey($validated['item_id'])->firstOrFail();
        $this->authorize('view', $item);
        $this->authorize('redeem', [RewardBenefitGrant::class, (string) $user->getKey()]);

        try {
            $grant = RedeemRewardBenefitAction::run(
                $user->getMorphClass(), (string) $user->getKey(), (string) $validated['family_key'],
                $item, (int) ($validated['quantity'] ?? 1), (string) $validated['redemption_key'],
                (string) ($validated['adapter'] ?? 'manual'),
                'user', (string) $user->getKey(), 'member',
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => self::grantSummary($grant)], 201);
    }

    public function grants(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        $this->authorize('viewAny', RewardBenefitGrant::class);

        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(array_map(static fn (RewardBenefitGrantStatus $status): string => $status->value, RewardBenefitGrantStatus::cases()))],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $perPage = ApiPagination::normalizePerPage((int) ($validated['per_page'] ?? 20), 20, 50);

        $query = RewardBenefitGrant::query()
            ->where('recipient_type', $user->getMorphClass())
            ->where('recipient_id', $user->getKey())
            ->orderByDesc('reserved_at');

        if (is_string($validated['status'] ?? null)) {
            $query->where('status', $validated['status']);
        }

        $grants = $query->paginate($perPage);

        return response()->json([
            'data' => $grants->getCollection()->map(static fn (RewardBenefitGrant $grant): array => self::grantSummary($grant))->all(),
            'meta' => ApiPagination::paginationMeta($grants),
        ]);
    }

    public function show(Request $request, string $grant): JsonResponse
    {
        $user = $this->requireUser($request);

        $record = RewardBenefitGrant::query()
            ->where('recipient_type', $user->getMorphClass())
            ->where('recipient_id', $user->getKey())
            ->whereKey($grant)
            ->firstOrFail();
        $this->authorize('view', $record);

        return response()->json(['data' => self::grantSummary($record)]);
    }

    public function consume(Request $request, string $grant): JsonResponse
    {
        $user = $this->requireUser($request);

        $validated = $request->validate([
            'use_reference' => ['nullable', 'string', 'max:191'],
            'voucher_usage_id' => ['nullable', 'string', 'max:191'],
        ]);

        $record = RewardBenefitGrant::query()
            ->where('recipient_type', $user->getMorphClass())
            ->where('recipient_id', $user->getKey())
            ->whereKey($grant)
            ->firstOrFail();
        $this->authorize('consume', $record);

        try {
            $consumed = ConsumeRewardBenefitAction::run(
                $record, array_filter($validated), 'user', (string) $user->getKey(), 'member',
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => self::grantSummary($consumed)]);
    }

    public function cancel(Request $request, string $grant): JsonResponse
    {
        $user = $this->requireUser($request);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:191'],
        ]);

        $record = RewardBenefitGrant::query()
            ->where('recipient_type', $user->getMorphClass())
            ->where('recipient_id', $user->getKey())
            ->whereKey($grant)
            ->firstOrFail();
        $this->authorize('cancel', $record);

        try {
            $cancelled = CancelRewardBenefitGrantAction::run(
                $record, 'user', (string) $user->getKey(), 'member', (string) $validated['reason'],
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => self::grantSummary($cancelled)]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function grantSummary(RewardBenefitGrant $grant): array
    {
        $item = RewardBenefitItem::query()->whereKey((string) $grant->item_id)->first();

        return [
            'id' => (string) $grant->getKey(),
            'benefit_key' => $item instanceof RewardBenefitItem ? (string) $item->benefit_key : null,
            'title' => $item instanceof RewardBenefitItem ? (string) $item->title : null,
            'quantity' => (int) $grant->quantity,
            'status' => $grant->status->value,
            'fulfillment_adapter' => (string) $grant->fulfillment_adapter,
            'external_ref' => $grant->external_ref,
            'reserved_at' => $grant->reserved_at?->toIso8601String(),
            'issued_at' => $grant->issued_at?->toIso8601String(),
            'consumed_at' => $grant->consumed_at?->toIso8601String(),
            'revoked_at' => $grant->revoked_at?->toIso8601String(),
            'expired_at' => $grant->expired_at?->toIso8601String(),
        ];
    }
}
