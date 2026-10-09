<?php

declare(strict_types=1);

namespace App\Support\Rewards;

use App\Contracts\Rewards\RewardBenefitCatalog;
use App\Enums\Rewards\RewardBenefitGrantStatus;
use App\Enums\Rewards\RewardBenefitItemStatus;
use App\Enums\Rewards\RewardBenefitStockKind;
use App\Enums\Rewards\RewardComponent;
use App\Models\RewardAward;
use App\Models\RewardBenefitGrant;
use App\Models\RewardBenefitItem;
use App\Models\RewardProgram;
use App\Models\RewardProgramVersion;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Catalog-backed benefit readiness and direct-entitlement fulfillment.
 *
 * coversProgram: every benefit_key configured on the program's current
 * version resolves to an active, unexpired catalog item with remaining
 * capacity. assess: per-award approval needs the same for the award's
 * frozen benefit_key; fulfillment additionally needs the direct grant at
 * a submittable gate. fulfill (invoked inside the award transition):
 * reserves one stock unit and issues the direct grant — no points move,
 * because direct entitlements are granted, not bought.
 */
final class RewardBenefitCatalogService implements RewardBenefitCatalog
{
    public function coversProgram(RewardProgram $program, ?RewardProgramVersion $version): bool
    {
        $version ??= $program->currentVersion()->first();

        if (! $version instanceof RewardProgramVersion) {
            return false;
        }

        $keys = $this->configuredBenefitKeys($version->rules);

        if ($keys === []) {
            return false;
        }

        foreach ($keys as $key) {
            if (! $this->itemReady((string) $program->getKey(), $key)) {
                return false;
            }
        }

        return true;
    }

    public function assess(RewardAward $award, bool $fulfill): RewardFinancialReadinessAssessment
    {
        if ($award->component !== RewardComponent::Benefit) {
            return new RewardFinancialReadinessAssessment(ready: true);
        }

        $key = $award->amount_snapshot['benefit_key'] ?? null;

        if (! is_string($key) || $key === '') {
            return new RewardFinancialReadinessAssessment(
                ready: false,
                blockers: ['benefit-key-unreadable'],
            );
        }

        if (! $this->itemReady((string) $award->program_id, $key)) {
            return new RewardFinancialReadinessAssessment(
                ready: false,
                blockers: ['benefit-item-unavailable:'.$key],
                notes: ['The catalog item is missing, retired, expired, or out of stock.'],
            );
        }

        if (! $fulfill) {
            return new RewardFinancialReadinessAssessment(
                ready: true,
                notes: ['Catalog item is available for the frozen benefit key.'],
            );
        }

        $grant = RewardBenefitGrant::query()
            ->where('award_id', $award->getKey())
            ->first();

        if ($grant instanceof RewardBenefitGrant
            && ! in_array($grant->status, [RewardBenefitGrantStatus::Reserved, RewardBenefitGrantStatus::Issued], true)
        ) {
            return new RewardFinancialReadinessAssessment(
                ready: false,
                blockers: ['benefit-grant-not-issuable:'.$grant->status->value],
            );
        }

        return new RewardFinancialReadinessAssessment(
            ready: true,
            notes: ['Direct grant issues inside the fulfillment transition.'],
        );
    }

    public function fulfill(RewardAward $award, CarbonImmutable $decided): void
    {
        $key = $award->amount_snapshot['benefit_key'] ?? null;

        if (! is_string($key) || $key === '') {
            throw new InvalidArgumentException('Refused: benefit award carries no frozen benefit key.');
        }

        $grantKey = 'award-grant:'.(string) $award->getKey();

        $existing = RewardBenefitGrant::query()->where('grant_key', $grantKey)->lockForUpdate()->first();

        if ($existing instanceof RewardBenefitGrant) {
            return;
        }

        $item = RewardBenefitItem::query()
            ->where('program_id', (string) $award->program_id)
            ->where('benefit_key', $key)
            ->lockForUpdate()
            ->first();

        if (! $item instanceof RewardBenefitItem) {
            throw new InvalidArgumentException("Refused: benefit catalog item [{$key}] is missing.");
        }

        if (! $item->direct_entitlement) {
            throw new InvalidArgumentException("Refused: benefit item [{$key}] is point-funded, not a direct entitlement.");
        }

        if ($item->status !== RewardBenefitItemStatus::Active) {
            throw new InvalidArgumentException("Refused: benefit item [{$key}] is retired.");
        }

        if ($item->expires_at !== null && ! $item->expires_at->greaterThan($decided)) {
            throw new InvalidArgumentException("Refused: benefit item [{$key}] has expired.");
        }

        if (RewardBenefitStockLedger::lockedBalances($item)['available'] < 1) {
            throw new InvalidArgumentException("Refused: benefit item [{$key}] is out of stock.");
        }

        $grant = RewardBenefitGrant::query()->create([
            'grant_key' => $grantKey,
            'item_id' => $item->getKey(),
            'recipient_type' => (string) $award->recipient_type,
            'recipient_id' => (string) $award->recipient_id,
            'award_id' => $award->getKey(),
            'reservation_key' => null,
            'quantity' => 1,
            'status' => RewardBenefitGrantStatus::Reserved,
            'fulfillment_adapter' => 'manual',
            'actor_type' => 'system',
            'actor_id' => null,
            'actor_role' => 'system',
            'reserved_at' => $decided,
        ]);

        RewardBenefitStockLedger::append(
            RewardBenefitStockKind::Reserve, "stock-reserve:{$grantKey}",
            (string) $item->getKey(), 1, (string) $grant->getKey(),
            'system', null, 'system', $decided,
        );
    }

    /**
     * @param  array<string, mixed>|null  $rules
     * @return list<string>
     */
    private function configuredBenefitKeys(mixed $rules): array
    {
        if (! is_array($rules) || ! is_array($rules['components'] ?? null)) {
            return [];
        }

        $keys = [];

        foreach ($rules['components'] as $component) {
            if (is_array($component)
                && ($component['type'] ?? null) === RewardComponent::Benefit->value
                && is_string($component['benefit_key'] ?? null)
            ) {
                $keys[] = $component['benefit_key'];
            }
        }

        return array_values(array_unique($keys));
    }

    private function itemReady(string $programId, string $key): bool
    {
        $item = RewardBenefitItem::query()
            ->where('program_id', $programId)
            ->where('benefit_key', $key)
            ->first();

        if (! $item instanceof RewardBenefitItem) {
            return false;
        }

        if ($item->status !== RewardBenefitItemStatus::Active) {
            return false;
        }

        if ($item->expires_at !== null && ! $item->expires_at->greaterThan(CarbonImmutable::now('UTC'))) {
            return false;
        }

        return RewardBenefitStockLedger::balances($item)['available'] >= 1;
    }
}
