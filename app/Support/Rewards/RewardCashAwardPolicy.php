<?php

declare(strict_types=1);

namespace App\Support\Rewards;

use AIArmada\Affiliates\Contracts\AffiliateAwardPolicy;
use AIArmada\Affiliates\Data\AffiliateAwardDecision;
use AIArmada\Affiliates\Data\AffiliateAwardProposal;
use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Rewards\Enums\RewardAwardStatus;
use AIArmada\Rewards\Enums\RewardComponent;
use AIArmada\Rewards\Enums\RewardPayoutRequestStatus;
use AIArmada\Rewards\Models\RewardAward;
use AIArmada\Rewards\Models\RewardPayoutRequest;
use AIArmada\Rewards\Support\RewardBudgetLedger;
use App\Support\ShareTracking\ShareTrackingProfile;

/**
 * Host authorization for reward cash backing conversions.
 *
 * Backing conversions are posted ONLY for approved cash awards whose payout
 * request reached independent approval with matching amount/currency and a
 * verified frozen payee snapshot. The award key namespace is
 * `reward-cash:{award_id}`; any other key is denied — this policy never
 * authorizes commercial or foreign postings. Read-only: same-connection
 * locked reads only, no writes, deterministic per recipient + proposal.
 */
final class RewardCashAwardPolicy implements AffiliateAwardPolicy
{
    public function decide(Affiliate $recipient, AffiliateAwardProposal $proposal): AffiliateAwardDecision
    {
        if ((string) $recipient->purpose !== ShareTrackingProfile::PURPOSE_SHARE_REWARD_RECIPIENT) {
            return AffiliateAwardDecision::deny('Recipient is not a purpose-marked reward recipient affiliate.');
        }

        if (! str_starts_with($proposal->awardKey, 'reward-cash:')) {
            return AffiliateAwardDecision::deny('Award key is outside the reward-cash namespace.');
        }

        $awardId = mb_substr($proposal->awardKey, mb_strlen('reward-cash:'));

        if ($awardId === '') {
            return AffiliateAwardDecision::deny('Reward cash award key carries no award id.');
        }

        $award = RewardAward::query()->whereKey($awardId)->lockForUpdate()->first();

        if (! $award instanceof RewardAward) {
            return AffiliateAwardDecision::deny('Unknown reward award.');
        }

        if ($award->component !== RewardComponent::Cash) {
            return AffiliateAwardDecision::deny('Backing conversions post for cash awards only.');
        }

        if ($award->status !== RewardAwardStatus::Approved) {
            return AffiliateAwardDecision::deny('Cash backing requires an approved award.');
        }

        $metadata = $recipient->metadata ?? [];
        $recipientType = (string) ($metadata['recipient_type'] ?? '');
        $recipientId = (string) ($metadata['recipient_id'] ?? '');

        if ($recipientType === '' || $recipientId === ''
            || $recipientType !== (string) $award->recipient_type
            || $recipientId !== (string) $award->recipient_id
        ) {
            return AffiliateAwardDecision::deny('Recipient binding does not match the award recipient.');
        }

        $request = RewardPayoutRequest::query()->where('award_id', $award->getKey())->lockForUpdate()->first();

        if (! $request instanceof RewardPayoutRequest) {
            return AffiliateAwardDecision::deny('Cash backing requires an approved payout request.');
        }

        if ($request->status !== RewardPayoutRequestStatus::Approved) {
            return AffiliateAwardDecision::deny('Cash backing requires an independently approved payout request.');
        }

        // Exact fixed posting against the FROZEN backing: program-paid
        // fees gross up the backing so the installed provider deduction
        // leaves the promised net exactly. The proposal must still match
        // to the minor unit — the level moved from net to frozen gross,
        // the exactness invariant did not.
        $backingTotal = (int) $request->amount_minor
            + ((string) $request->fee_payer === 'program' ? (int) $request->fee_minor : 0);

        if ($backingTotal !== $proposal->amountMinor
            || (string) $request->currency !== $proposal->currency
        ) {
            return AffiliateAwardDecision::deny('Proposal amount/currency must match the frozen gross backing exactly.');
        }

        if ($request->external_reference_id !== null) {
            return AffiliateAwardDecision::deny('Cash backing is already posted for this payout request.');
        }

        $balances = RewardBudgetLedger::lockedBalances(
            (string) $award->program_id,
            (string) $request->currency,
            RewardBudgetLedger::lockFunding((string) $award->program_id, (string) $request->currency),
        );

        if ($backingTotal > $balances['funded'] - $balances['net_spent']) {
            return AffiliateAwardDecision::deny('Confirmed funding no longer covers the frozen gross backing.');
        }

        return AffiliateAwardDecision::allow(
            policyMinMinor: $proposal->amountMinor,
            policyMaxMinor: $proposal->amountMinor,
            reason: 'Approved cash award with approved payout request and confirmed funding.',
        );
    }
}
