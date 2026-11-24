<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\Actions;

use App\Features\DonationChannels\Models\DonationBank;
use App\Features\DonationChannels\Queries\DonationBankReferences;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Delete a bank catalog entry that no donation channel references.
 *
 * Referenced entries cannot be deleted: existing channels would lose the
 * catalog row behind their stored code, so retirement is the supported path.
 */
final readonly class DeleteDonationBankAction
{
    public function __construct(private DonationBankReferences $references) {}

    public function handle(DonationBank $bank): void
    {
        Gate::authorize('delete', $bank);

        $references = $this->references->count($bank);

        if ($references > 0) {
            throw ValidationException::withMessages([
                'bank' => __(':count donation channel(s) use this bank, so it cannot be deleted; retire it instead.', [
                    'count' => $references,
                ]),
            ]);
        }

        $bank->delete();
    }
}
