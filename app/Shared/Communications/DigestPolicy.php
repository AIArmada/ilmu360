<?php

declare(strict_types=1);

namespace App\Shared\Communications;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Recipient digest timing and consent policy.
 *
 * Consumers receive scalars only. The Communications implementation maps
 * configured notification settings, cadence/trigger enums and global digest
 * defaults into an immutable snapshot so Rewards never imports internal
 * notification settings services or their enums.
 */
interface DigestPolicy
{
    public function resolve(Authenticatable $recipient, string $trigger): DigestPolicySnapshot;
}
