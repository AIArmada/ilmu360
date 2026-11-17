<?php
declare(strict_types=1);
namespace App\Features\Referrals\Api;
interface ReferralInvitations
{
    /** @return array{outcome: string, invite_id: string|null, program_id: string|null} */
    public function land(string $token, string $actorId): array;
}
