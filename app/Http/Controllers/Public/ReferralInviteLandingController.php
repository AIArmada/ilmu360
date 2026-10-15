<?php

namespace App\Http\Controllers\Public;

use App\Actions\Referrals\ResolveReferralInviteLandingAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Signals\ProductSignalsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

final class ReferralInviteLandingController extends Controller
{
    public function __invoke(Request $request, string $token): View
    {
        $request->session()->put('referrals.invite_token', $token);
        $user = $request->user();

        if (! $user instanceof User) {
            $request->session()->put('url.intended', route('referrals.invite.landing', ['token' => $token]));

            return view('referrals.invite-landing', [
                'outcome' => 'sign_in_required',
                'message' => 'Log masuk atau daftar masuk dahulu untuk menerima jemputan ini.',
            ]);
        }

        $stashedToken = $request->session()->pull('referrals.invite_token');
        $landingToken = is_string($stashedToken) && hash_equals($token, $stashedToken)
            ? $stashedToken
            : $token;
        $result = app(ResolveReferralInviteLandingAction::class)->handle($landingToken, $user);
        $outcome = $result['outcome'];

        if ($outcome === 'accepted' && $result['invite'] !== null) {
            try {
                app(ProductSignalsService::class)->recordReferralInviteAccepted(
                    $result['invite'],
                    $user,
                    $request,
                );
            } catch (Throwable $exception) {
                Log::warning('The referral invite was accepted but its product signal could not be recorded.', [
                    'invite_id' => (string) $result['invite']->getKey(),
                    'exception' => $exception,
                ]);
            }
        }

        return view('referrals.invite-landing', [
            'outcome' => $outcome,
            'message' => $this->messageFor($outcome),
        ]);
    }

    private function messageFor(string $outcome): string
    {
        return match ($outcome) {
            'accepted' => 'Jemputan telah diterima. Selesaikan pendaftaran majlis atau daftar masuk yang disahkan kakitangan untuk melayakkan ganjaran program.',
            'contact_mismatch' => 'E-mel atau nombor telefon yang disahkan tidak sepadan dengan jemputan ini.',
            'expired' => 'Jemputan ini telah tamat tempoh.',
            'revoked' => 'Jemputan ini telah dibatalkan.',
            'already_claimed' => 'Jemputan ini telah diterima atau dituntut.',
            'self_invite' => 'Anda tidak boleh menerima jemputan anda sendiri.',
            'no_verified_contact' => 'Sahkan e-mel atau nombor telefon anda sebelum menerima jemputan.',
            'program_inactive' => 'Program jemputan ini tidak menerima jemputan buat masa ini.',
            default => 'Jemputan ini tidak tersedia atau pautannya tidak sah.',
        };
    }
}
