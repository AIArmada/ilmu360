<?php

declare(strict_types=1);

namespace App\Actions\Rewards;

use App\Enums\Rewards\RewardBenefitItemStatus;
use App\Models\RewardBenefitItem;
use App\Models\RewardProgram;
use App\Support\Rewards\RewardLedgerEntries;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Create a benefit catalog item (operator setup input).
 *
 * Terms freeze at creation: either a point cost (cost_points + cost_unit)
 * or a direct entitlement (granted by benefit awards without points),
 * never both and never neither. Capacity is the fixed total unit count;
 * a revision is a new item row. Expiry is an absolute UTC instant.
 */
final readonly class CreateRewardBenefitItemAction
{
    use AsAction;

    /**
     * @param  list<string>  $conditions
     * @param  array<string, mixed>|null  $fulfillmentConfig
     */
    public function handle(
        RewardProgram|string $program,
        string $benefitKey,
        string $title,
        array $conditions,
        ?int $costPoints,
        ?string $costUnit,
        bool $directEntitlement,
        int $capacity,
        string $actorType,
        ?string $actorId,
        string $actorRole,
        ?CarbonInterface $expiresAt = null,
        ?array $fulfillmentConfig = null,
    ): RewardBenefitItem {
        RewardLedgerEntries::assertActor($actorType, $actorId, $actorRole);

        if ($actorType !== 'user' || ! in_array($actorRole, ['staff', 'admin'], true)) {
            throw new InvalidArgumentException('Benefit catalog changes require a staff/admin user identity.');
        }

        if (preg_match('/^[a-z0-9][a-z0-9_\\-]{0,63}$/', $benefitKey) !== 1) {
            throw new InvalidArgumentException('Benefit key must be a 1..64 slug.');
        }

        if ($title === '' || mb_strlen($title) > 191) {
            throw new InvalidArgumentException('Benefit title must be 1..191 characters.');
        }

        if ($conditions === [] || count($conditions) > 20) {
            throw new InvalidArgumentException('Benefit conditions must be an explicit list of 1..20 entries.');
        }

        foreach ($conditions as $condition) {
            if (! is_string($condition) || $condition === '' || mb_strlen($condition) > 191) {
                throw new InvalidArgumentException('Benefit conditions must be non-empty strings up to 191 characters.');
            }
        }

        $hasCost = $costPoints !== null || $costUnit !== null;

        if ($directEntitlement === $hasCost) {
            throw new InvalidArgumentException('Benefit items take either a point cost or direct entitlement, never both or neither.');
        }

        if ($hasCost) {
            if ($costPoints === null || $costPoints < 1) {
                throw new InvalidArgumentException('Benefit point cost must be a positive integer.');
            }

            if (! is_string($costUnit) || preg_match('/^[A-Za-z0-9][A-Za-z0-9_\\-]{0,15}$/', $costUnit) !== 1) {
                throw new InvalidArgumentException('Benefit cost unit must be 1..16 alphanumerics.');
            }
        }

        if ($capacity < 1) {
            throw new InvalidArgumentException('Benefit capacity must be a positive integer.');
        }

        $fulfillmentConfig = $this->validateFulfillmentConfig($fulfillmentConfig);

        $expiry = $expiresAt === null ? null : CarbonImmutable::parse($expiresAt)->setTimezone('UTC');
        $programId = $program instanceof RewardProgram ? (string) $program->getKey() : $program;

        return DB::transaction(function () use (
            $programId, $benefitKey, $title, $conditions, $costPoints, $costUnit,
            $directEntitlement, $capacity, $expiry, $fulfillmentConfig,
        ): RewardBenefitItem {
            $exists = RewardProgram::query()->whereKey($programId)->lockForUpdate()->first();

            if (! $exists instanceof RewardProgram) {
                throw new InvalidArgumentException('Unknown reward program.');
            }

            return RewardBenefitItem::query()->create([
                'program_id' => $programId,
                'benefit_key' => $benefitKey,
                'title' => $title,
                'conditions' => array_values($conditions),
                'cost_points' => $costPoints,
                'cost_unit' => $costUnit,
                'direct_entitlement' => $directEntitlement,
                'capacity' => $capacity,
                'fulfillment_config' => $fulfillmentConfig,
                'status' => RewardBenefitItemStatus::Active,
                'expires_at' => $expiry,
            ]);
        });
    }

    /**
     * Coupon-capable items declare fixed-value voucher terms explicitly:
     * `{'voucher': {'type': 'fixed', 'value': <minor int>, 'currency': 'MYR'}}`.
     * Only fixed value maps honestly onto benefit redemption; percentage
     * and cart-shape vouchers are refused rather than reinterpreted.
     *
     * @param  array<string, mixed>|null  $config
     * @return array<string, mixed>|null
     */
    private function validateFulfillmentConfig(?array $config): ?array
    {
        if ($config === null) {
            return null;
        }

        $voucher = $config['voucher'] ?? null;

        if (! is_array($voucher)) {
            throw new InvalidArgumentException('Fulfillment config must declare a voucher section or be omitted.');
        }

        if (($voucher['type'] ?? null) !== 'fixed') {
            throw new InvalidArgumentException('Benefit coupon vouchers must be fixed-value.');
        }

        $value = $voucher['value'] ?? null;
        $currency = $voucher['currency'] ?? null;

        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException('Benefit coupon voucher value must be a positive minor-unit integer.');
        }

        if (! is_string($currency) || preg_match('/^[A-Z]{3}$/', mb_strtoupper(mb_trim($currency))) !== 1) {
            throw new InvalidArgumentException('Benefit coupon voucher currency must be a 3-letter ISO-4217 code.');
        }

        return ['voucher' => [
            'type' => 'fixed',
            'value' => $value,
            'currency' => mb_strtoupper(mb_trim($currency)),
        ]];
    }
}
