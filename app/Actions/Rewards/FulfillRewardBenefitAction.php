<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use AIArmada\Vouchers\Actions\AddVoucherToWallet;
use AIArmada\Vouchers\Actions\CreateVoucher;
use App\Enums\Rewards\RewardBenefitGrantStatus;
use App\Enums\Rewards\RewardBenefitStockKind;
use App\Enums\Rewards\RewardPointMovementKind;
use App\Models\RewardBenefitGrant;
use App\Models\RewardBenefitItem;
use App\Models\RewardPointLot;
use App\Models\RewardPointMovement;
use App\Models\RewardPointReservationSubmission;
use App\Models\User;
use App\Support\Rewards\RewardBenefitStockLedger;
use App\Support\Rewards\RewardLedgerEntries;
use App\Support\Rewards\RewardLedgerLockOrder;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Fulfill a reserved grant: capture the held points, consume the held
 * stock, and issue exactly once.
 *
 * Manual adapter: the operator presents handoff evidence (who received
 * what, which reference) and the grant issues; the reservation closes as
 * delivered. Coupon adapter: the item's frozen fixed-value voucher terms
 * issue a recipient-bound single-use voucher into the recipient's wallet
 * (issuance consumes once; canonical usage is recorded only on actual
 * use via ConsumeRewardBenefitAction). Fulfill refuses on expired items —
 * the operator expires or cancels the grant instead.
 */
final readonly class FulfillRewardBenefitAction
{
    use AsAction;

    /**
     * @param  array<string, mixed>  $handoffEvidence
     */
    public function handle(
        RewardBenefitGrant|string $grant,
        array $handoffEvidence,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        ?CarbonInterface $decidedAt = null,
    ): RewardBenefitGrant {
        RewardLedgerEntries::assertActor($actorType, $actorId, $actorRole);

        if ($actorType !== 'user' || ! in_array($actorRole, ['staff', 'admin'], true)) {
            throw new InvalidArgumentException('Benefit fulfillment requires a staff/admin user identity.');
        }

        $grantId = $grant instanceof RewardBenefitGrant ? (string) $grant->getKey() : $grant;
        $decided = $decidedAt === null
            ? CarbonImmutable::now('UTC')
            : CarbonImmutable::parse($decidedAt)->setTimezone('UTC');

        return DB::transaction(function () use ($grantId, $handoffEvidence, $actorType, $actorId, $actorRole, $decided): RewardBenefitGrant {
            $locked = RewardBenefitGrant::query()->whereKey($grantId)->lockForUpdate()->first();

            if (! $locked instanceof RewardBenefitGrant) {
                throw new InvalidArgumentException('Unknown reward benefit grant.');
            }

            if ($locked->status !== RewardBenefitGrantStatus::Reserved) {
                throw new InvalidArgumentException('Only reserved grants can be fulfilled.');
            }

            $item = RewardBenefitItem::query()->whereKey((string) $locked->item_id)->lockForUpdate()->firstOrFail();

            if ($item->expires_at !== null && ! $item->expires_at->greaterThan($decided)) {
                throw new InvalidArgumentException('Benefit item has expired; expire or cancel the grant instead.');
            }

            $externalRef = null;

            if ((string) $locked->fulfillment_adapter === 'coupon') {
                $externalRef = $this->issueCoupon($locked, $item, $decided);
            } else {
                $reference = mb_trim((string) ($handoffEvidence['handoff_reference'] ?? ''));

                if ($reference === '' || mb_strlen($reference) > 191) {
                    throw new InvalidArgumentException('Manual fulfillment requires handoff_reference evidence of 1..191 characters.');
                }

                $externalRef = $reference;
            }

            if ($locked->reservation_key !== null) {
                $this->captureReservation($locked, $decided, $actorType, $actorId, $externalRef);
            }

            RewardBenefitStockLedger::append(
                RewardBenefitStockKind::Consume, "stock-consume:{$locked->grant_key}",
                (string) $item->getKey(), (int) $locked->quantity, (string) $locked->getKey(),
                $actorType, $actorId, $actorRole, $decided,
            );

            $evidence = $locked->evidence ?? [];
            $evidence['fulfillment'] = $handoffEvidence;

            $locked->forceFill([
                'status' => RewardBenefitGrantStatus::Issued,
                'external_ref' => $externalRef,
                'evidence' => $evidence,
                'issued_at' => $decided,
            ])->save();

            return $locked->refresh();
        });
    }

    private function captureReservation(
        RewardBenefitGrant $grant,
        CarbonImmutable $decided,
        string $actorType,
        ?string $actorId,
        string $externalRef,
    ): void {
        // Canonical lots-before-movements order: read the touched lot ids,
        // lock the lots in sorted order, then lock the reserve pieces.
        // Every other point path (reserve, debit, refund, clawback, mark)
        // takes the same direction, so fulfill-vs-reversal converges
        // instead of deadlocking.
        $lotIds = RewardPointMovement::query()
            ->where('reservation_key', (string) $grant->reservation_key)
            ->where('kind', RewardPointMovementKind::Reserve)
            ->orderBy('id')
            ->pluck('lot_id')
            ->map(static fn ($lotId): string => (string) $lotId)
            ->unique()
            ->values()
            ->all();

        if ($lotIds === []) {
            throw new InvalidArgumentException('Grant reservation has no held pieces; refusing to fulfill.');
        }

        foreach (RewardLedgerLockOrder::sorted($lotIds) as $lotId) {
            RewardPointLot::query()->whereKey($lotId)->lockForUpdate()->first();
        }

        $reserves = RewardPointMovement::query()
            ->where('reservation_key', (string) $grant->reservation_key)
            ->where('kind', RewardPointMovementKind::Reserve)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($reserves->isEmpty()) {
            throw new InvalidArgumentException('Grant reservation has no held pieces; refusing to fulfill.');
        }

        $total = (int) $reserves->sum('delta');

        $lots = RewardPointLot::query()->whereIn('id', $lotIds)->lockForUpdate()->get();

        if ($lots->isEmpty() || $total <= 0) {
            throw new InvalidArgumentException('Grant reservation pieces are incoherent; refusing to fulfill.');
        }

        $familyKey = (string) $lots->firstOrFail()->family_key;
        $unit = (string) $lots->firstOrFail()->unit;

        if ($lots->some(static fn (RewardPointLot $lot): bool => (string) $lot->family_key !== $familyKey || (string) $lot->unit !== $unit)) {
            throw new InvalidArgumentException('Grant reservation spans families or units; refusing to fulfill.');
        }

        DebitRewardPointsAction::run(
            (string) $grant->recipient_type, (string) $grant->recipient_id, $familyKey, $unit, $total,
            "capture:{$grant->reservation_key}", "benefit-fulfillment:{$grant->grant_key}",
            (string) $grant->reservation_key, $decided, $actorType, $actorId,
        );

        MarkRewardReservationSubmittedAction::run(
            (string) $grant->reservation_key,
            (string) $grant->fulfillment_adapter === 'coupon'
                ? RewardPointReservationSubmission::STATUS_SUBMITTED
                : RewardPointReservationSubmission::STATUS_DELIVERED,
            $externalRef, $decided,
        );
    }

    private function issueCoupon(
        RewardBenefitGrant $grant,
        RewardBenefitItem $item,
        CarbonImmutable $decided,
    ): string {
        $config = $item->fulfillment_config['voucher'] ?? null;

        if (! is_array($config)) {
            throw new InvalidArgumentException('Coupon fulfillment requires frozen voucher terms on the catalog item.');
        }

        if ((string) $grant->recipient_type !== 'user') {
            throw new InvalidArgumentException('Coupon fulfillment binds to user recipients only.');
        }

        $holder = User::query()->whereKey((string) $grant->recipient_id)->first();

        if (! $holder instanceof User) {
            throw new InvalidArgumentException('Coupon recipient user is missing.');
        }

        $code = 'RB-'.mb_strtoupper(mb_substr(hash('sha256', (string) $grant->grant_key), 0, 12));

        // Recipient-bound issuance: the generic assignee marker (not the
        // per-user usage limit, and not the wallet association) is the
        // actual recipient eligibility. Validation and usage recording
        // refuse any other account; unmarked commercial vouchers are
        // unaffected.
        app(CreateVoucher::class)->handle([
            'code' => $code,
            'name' => mb_substr((string) $item->title, 0, 120),
            'type' => 'fixed',
            'value' => ((int) $config['value']) * ((int) $grant->quantity),
            'currency' => (string) $config['currency'],
            'usage_limit' => 1,
            'usage_limit_per_user' => 1,
            'starts_at' => $decided,
            'expires_at' => $item->expires_at,
            'description' => 'Reward benefit grant '.(string) $grant->grant_key,
            'metadata' => [
                'assignee' => ['type' => $holder->getMorphClass(), 'id' => (string) $holder->getKey()],
                'grant_key' => (string) $grant->grant_key,
            ],
        ]);

        app(AddVoucherToWallet::class)->handle($code, $holder, [
            'grant_key' => (string) $grant->grant_key,
            'benefit_key' => (string) $item->benefit_key,
        ]);

        return $code;
    }
}
