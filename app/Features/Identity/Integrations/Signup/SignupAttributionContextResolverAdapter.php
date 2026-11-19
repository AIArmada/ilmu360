<?php

declare(strict_types=1);

namespace App\Features\Identity\Integrations\Signup;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Referrals\Exceptions\ReferralInviteNotFoundException;
use AIArmada\Referrals\Support\ReferralProgramGuard;
use App\Features\Identity\Api\SignupAttributionContext;
use App\Features\Identity\Api\SignupAttributionContextResolver;
use App\Features\Identity\Api\SignupAttributionSourceResolver;

final readonly class SignupAttributionContextResolverAdapter implements SignupAttributionContextResolver
{
    public function __construct(private SignupAttributionSourceResolver $sourceResolver) {}

    public function resolve(): SignupAttributionContext
    {
        $request = request();

        $token = $request->input('referral_invite_token');

        if (! is_string($token) && $request->hasSession()) {
            $token = $request->session()->get('referrals.invite_token');
        }

        $inviteId = null;

        if (is_string($token) && preg_match('/^[A-Za-z0-9]{32}$/D', $token) === 1) {
            try {
                $invite = OwnerContext::withOwner(null, fn () => app(ReferralProgramGuard::class)->inviteByToken($token));
                $inviteId = (string) $invite->getKey();
            } catch (ReferralInviteNotFoundException) {
                $inviteId = null;
            }
        }

        return new SignupAttributionContext(
            referralInviteId: $inviteId,
            affiliateSource: $this->sourceResolver->resolve()?->toArray(),
        );
    }
}
