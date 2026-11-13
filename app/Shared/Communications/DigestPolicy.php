<?php

declare(strict_types=1);

namespace App\Shared\Communications;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Recipient digest timing and consent policy.
 *
 * Consumers receive scalars only. The root adapter maps the configured
 * notification settings, cadence/trigger enums and global digest defaults into
 * an immutable snapshot so feature code never imports the conventional
 * notification settings service or its enums.
 */
interface DigestPolicy
{
    public function resolve(Authenticatable $recipient, string $trigger): DigestPolicySnapshot;
}
