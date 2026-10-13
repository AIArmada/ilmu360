<?php

declare(strict_types=1);

namespace App\Support\Rewards;

use AIArmada\Rewards\Contracts\RewardBenefitCouponFulfillment;
use AIArmada\Rewards\Models\RewardBenefitGrant;
use AIArmada\Rewards\Models\RewardBenefitItem;
use AIArmada\Vouchers\Actions\AddVoucherToWallet;
use AIArmada\Vouchers\Actions\CreateVoucher;
use AIArmada\Vouchers\Actions\ExpireVoucher;
use AIArmada\Vouchers\Models\Voucher as VoucherModel;
use AIArmada\Vouchers\Models\VoucherUsage;
use App\Models\User;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Vouchers-backed coupon fulfillment: issues recipient-bound single-use
 * coupons for benefit grants. Usage is proven against the canonical
 * voucher usage rows — issuance is never confused with use — and
 * revocation refuses once value moved.
 */
final class VouchersRewardBenefitCouponFulfillment implements RewardBenefitCouponFulfillment
{
    public function issue(RewardBenefitGrant $grant, RewardBenefitItem $item, CarbonImmutable $decided): string
    {
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

    public function assertCanonicalUsage(RewardBenefitGrant $grant, array $useEvidence): void
    {
        $voucher = VoucherModel::query()
            ->where('code', (string) $grant->external_ref)
            ->lockForUpdate()
            ->first();

        if (! $voucher instanceof VoucherModel) {
            throw new InvalidArgumentException('Issued coupon is missing; refusing consumption.');
        }

        $usageId = $useEvidence['voucher_usage_id'] ?? null;

        if (is_string($usageId) && $usageId !== '') {
            $usage = VoucherUsage::query()
                ->whereKey($usageId)
                ->where('voucher_id', $voucher->getKey())
                ->lockForUpdate()
                ->first();

            if (! $usage instanceof VoucherUsage) {
                throw new InvalidArgumentException('No canonical usage exists for the issued coupon; refusing consumption.');
            }
        } else {
            $matches = VoucherUsage::query()
                ->where('voucher_id', $voucher->getKey())
                ->where('redeemed_by_type', (string) $grant->recipient_type)
                ->where('redeemed_by_id', (string) $grant->recipient_id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($matches->isEmpty()) {
                throw new InvalidArgumentException('No canonical usage exists for the issued coupon; refusing consumption.');
            }

            if ($matches->count() > 1) {
                throw new InvalidArgumentException('Several usages exist for the issued coupon; cite the voucher_usage_id explicitly.');
            }

            $usage = $matches->firstOrFail();
        }

        if ((string) $usage->redeemed_by_type !== (string) $grant->recipient_type
            || (string) $usage->redeemed_by_id !== (string) $grant->recipient_id
        ) {
            throw new InvalidArgumentException('The cited usage was not redeemed by the grant recipient; refusing consumption.');
        }
    }

    public function revoke(RewardBenefitGrant $grant): void
    {
        // The voucher row lock serializes revocation against usage
        // recording (which holds the same row while inserting) and
        // against consumption (which locks voucher → usage the same
        // way): either the use commits first and revocation refuses, or
        // revocation commits first and later usage is refused as expired.
        $voucher = VoucherModel::query()
            ->where('code', (string) $grant->external_ref)
            ->lockForUpdate()
            ->first();

        if (! $voucher instanceof VoucherModel) {
            throw new InvalidArgumentException('Issued coupon is missing; refusing revocation.');
        }

        $used = VoucherUsage::query()
            ->where('voucher_id', $voucher->getKey())
            ->lockForUpdate()
            ->exists();

        if ($used) {
            throw new InvalidArgumentException('Coupon was used; revocation is closed — open a recovery case instead.');
        }

        app(ExpireVoucher::class)->handle((string) $grant->external_ref);
    }
}
