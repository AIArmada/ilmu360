<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\Queries;

use App\Features\DonationChannels\Models\DonationBank;

/**
 * Reads how many stored donation channels reference a catalog bank code.
 *
 * Referenced entries stay renameable and retirable but never deletable, and
 * their identifying country and code stay locked. The count is deliberately
 * code-scoped: donation_channels does not store the entry country and catalog
 * codes are unique per country in practice.
 */
final readonly class DonationBankReferences
{
    public function count(DonationBank $bank): int
    {
        return (int) $bank->referencedChannels()->count();
    }

    public function exists(DonationBank $bank): bool
    {
        return $this->count($bank) > 0;
    }
}
