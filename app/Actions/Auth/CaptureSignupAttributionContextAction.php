<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Referrals\Exceptions\ReferralInviteNotFoundException;
use AIArmada\Referrals\Support\ReferralProgramGuard;
use App\Contracts\SignupAttributionSourceResolver;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class CaptureSignupAttributionContextAction
{
    use AsAction;

    public function __construct(private SignupAttributionSourceResolver $sourceResolver) {}

    /** @return array{referral_invite_id: ?string, affiliate_source: array<string, mixed>|null} */
    public function handle(?Request $request): array
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

        return [
            'referral_invite_id' => $inviteId,
            'affiliate_source' => $this->sourceResolver->resolve($request)?->toArray(),
        ];
    }
}
