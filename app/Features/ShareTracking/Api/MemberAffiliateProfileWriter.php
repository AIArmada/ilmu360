<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Api;

/** Ensures or resolves a member share profile from the caller snapshot. */
interface MemberAffiliateProfileWriter
{
    public function ensureForUser(string $userId, string $name, ?string $email): MemberAffiliateProfileResult;
}
