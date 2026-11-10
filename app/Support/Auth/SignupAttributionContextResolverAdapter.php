<?php

declare(strict_types=1);

namespace App\Support\Auth;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Referrals\Exceptions\ReferralInviteNotFoundException;
use AIArmada\Referrals\Support\ReferralProgramGuard;
use App\Shared\Auth\SignupAttributionContext;
use App\Shared\Auth\SignupAttributionContextResolver;
use App\Shared\Auth\SignupAttributionSourceResolver;
use Illuminate\Http\Request;

final readonly class SignupAttributionContextResolverAdapter implements SignupAttributionContextResolver
{
    public function __construct(private SignupAttributionSourceResolver $sourceResolver) {}

    public function resolve(?Request $request): SignupAttributionContext
    {
        $token = $request?->input('referral_invite_token');

        if (! is_string($token) && $request?->hasSession()) {
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
            affiliateSource: $this->sourceResolver->resolve($request)?->toArray(),
        );
    }
}
