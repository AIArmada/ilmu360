<?php

declare(strict_types=1);

namespace App\Features\Identity\Actions;

use App\Features\Identity\Api\RecordSignupAttributionInput;
use App\Features\Identity\Api\SignupAttributionSnapshot;
use App\Features\Identity\Api\SignupAttributionStatus;
use App\Features\Identity\Models\SignupAttribution;
use App\Features\Identity\Services\SignupAttributionSnapshots;

/**
 * Persist the immutable acquisition source for a newly created account.
 */
final class RecordSignupAttributionAction
{
    public function handle(RecordSignupAttributionInput $input): SignupAttributionSnapshot
    {
        $attribution = SignupAttribution::query()->create([
            'user_id' => $input->accountId,
            'referrer_user_id' => $input->referrerAccountId,
            'source_type' => $input->sourceType,
            'status' => $input->status,
            'affiliate_id' => $input->affiliateId,
            'affiliate_link_id' => $input->affiliateLinkId,
            'affiliate_attribution_id' => $input->affiliateAttributionId,
            'referral_invite_id' => $input->referralInviteId,
            'invitee_contact_hash' => $input->inviteeContactHash,
            'signed_up_at' => $input->signedUpAt,
            'confirmed_at' => $input->status === SignupAttributionStatus::Confirmed ? now() : null,
        ]);

        return SignupAttributionSnapshots::fromModel($attribution);
    }
}
