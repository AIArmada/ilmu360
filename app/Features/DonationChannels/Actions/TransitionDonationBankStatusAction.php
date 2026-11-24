<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\Actions;

use App\Features\DonationChannels\Api\Enums\DonationBankStatus;
use App\Features\DonationChannels\Models\DonationBank;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

/**
 * Retire or reactivate a bank catalog entry and record its lifecycle timestamp.
 *
 * Retiring only removes the entry from new selection; stored donation channel
 * references keep resolving, so an existing verified payout is never silently
 * rewritten. Reactivating makes the entry selectable again.
 */
final readonly class TransitionDonationBankStatusAction
{
    public function handle(DonationBank $bank, DonationBankStatus $status): DonationBank
    {
        Gate::authorize('update', $bank);

        if ($bank->status === $status) {
            return $bank;
        }

        $now = CarbonImmutable::now();

        $bank->setAttribute('status', $status);
        $bank->setAttribute('last_state_change_at', $now);

        if ($status === DonationBankStatus::Retired) {
            $bank->setAttribute('retired_at', $now);
        } else {
            $bank->setAttribute('reactivated_at', $now);
        }

        $bank->save();

        return $bank->refresh();
    }
}
