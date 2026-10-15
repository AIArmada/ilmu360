<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Dashboard;

use AIArmada\CommerceSupport\Support\MoneyFormatter;
use AIArmada\Referrals\Actions\CreateReferralInvite;
use AIArmada\Referrals\Actions\RevokeReferralInvite;
use AIArmada\Referrals\Enums\ReferralInviteStatus;
use AIArmada\Referrals\Enums\ReferralProgramStatus;
use AIArmada\Referrals\Exceptions\ReferralAlreadyClaimedException;
use AIArmada\Referrals\Models\ReferralInvite;
use AIArmada\Referrals\Models\ReferralProgram;
use AIArmada\Referrals\Support\ReferralContactNormalizer;
use App\Models\User;
use App\Services\Signals\ProductSignalsService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use LogicException;
use Throwable;

#[Layout('layouts.app')]
class ReferralMemberIndex extends Component
{
    public string $inviteeContact = '';

    public string $shareLink = '';

    public string $notice = '';

    public function createInvite(): void
    {
        $this->shareLink = '';
        $program = $this->activeReferralProgram();

        if (! $program instanceof ReferralProgram) {
            $this->notice = 'Belum ada program jemputan yang aktif.';

            return;
        }

        $inviteExpiryDays = $this->inviteExpiryDays();

        if ($inviteExpiryDays < 1) {
            $this->notice = 'Tempoh jemputan belum ditetapkan oleh pengurus program.';

            return;
        }

        $this->validate([
            'inviteeContact' => ['required', 'string', 'max:191'],
        ]);

        $contact = trim($this->inviteeContact);
        $normalizer = app(ReferralContactNormalizer::class);

        try {
            $normalizedContact = $normalizer->normalize($contact);
        } catch (InvalidArgumentException) {
            $this->notice = 'Masukkan e-mel atau nombor telefon yang sah.';

            return;
        }

        $user = $this->currentUser();
        $verifiedContacts = $this->verifiedContacts($user);

        foreach ($verifiedContacts as $verifiedContact) {
            if ($normalizer->normalize($verifiedContact) === $normalizedContact) {
                $this->notice = 'You cannot invite your own verified contact.';

                return;
            }
        }

        try {
            $result = CreateReferralInvite::run(
                $program,
                $user,
                $contact,
                $verifiedContacts[0] ?? null,
            );
        } catch (ReferralAlreadyClaimedException) {
            $this->notice = 'This contact has already been claimed in this programme.';

            return;
        } catch (InvalidArgumentException) {
            $this->notice = 'Masukkan e-mel atau nombor telefon yang sah.';

            return;
        } catch (LogicException $exception) {
            $message = $exception->getMessage();
            $this->notice = str_contains($message, 'invite cap')
                ? 'You have reached this programme’s invite limit.'
                : 'This programme is not accepting invitations right now.';

            return;
        }

        if ($result->invite->wasRecentlyCreated) {
            try {
                app(ProductSignalsService::class)->recordReferralInviteCreated(
                    $result->invite,
                    $user,
                    request(),
                    'member-portal',
                );
            } catch (Throwable $exception) {
                Log::warning('The referral invite was created but its product signal could not be recorded.', [
                    'invite_id' => (string) $result->invite->getKey(),
                    'exception' => $exception,
                ]);
            }
        }

        $this->inviteeContact = '';

        if (! is_string($result->token) || $result->token === '') {
            $this->notice = 'This contact already has a claimed invitation; no new link was issued.';

            return;
        }

        $this->shareLink = route('referrals.invite.landing', ['token' => $result->token]);
        $this->notice = 'Jemputan berjaya disediakan.';
    }

    public function revokeInvite(string $inviteId): void
    {
        $user = $this->currentUser();
        $invite = ReferralInvite::query()
            ->where('referrer_type', $user->getMorphClass())
            ->where('referrer_id', (string) $user->getKey())
            ->whereKey($inviteId)
            ->firstOrFail();

        if ($invite->status !== ReferralInviteStatus::Pending) {
            $this->notice = 'Only pending invitations can be revoked.';

            return;
        }

        try {
            RevokeReferralInvite::run($invite, 'member-request');
        } catch (LogicException) {
            $this->notice = 'This invitation can no longer be revoked.';

            return;
        }

        $this->notice = 'Jemputan dibatalkan.';
    }

    #[Computed]
    public function activeReferralProgram(): ?ReferralProgram
    {
        $now = CarbonImmutable::now('UTC');

        return ReferralProgram::query()
            ->whereNull('owner_type')
            ->whereNull('owner_id')
            ->where('status', ReferralProgramStatus::Active->value)
            ->where(function ($query) use ($now): void {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($query) use ($now): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', $now);
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();
    }

    #[Computed]
    public function inviteExpiryDays(): int
    {
        return $this->activeReferralProgram()->invite_expiry_days
            ?? (int) config('referrals.defaults.invite_expiry_days', 30);
    }

    /** @param array<string, mixed>|null $spec */
    public function rewardDescription(?array $spec): string
    {
        if ($spec === null || $spec === []) {
            return 'Tiada ganjaran ditetapkan.';
        }

        if (($spec['driver'] ?? null) === 'reward_points' && is_int($spec['points'] ?? null)) {
            return number_format($spec['points']).' mata';
        }

        if (($spec['driver'] ?? null) === 'voucher'
            && is_int($spec['amount_minor'] ?? null)
            && is_string($spec['currency'] ?? null)) {
            $value = $spec['amount_minor'];
            $currency = $spec['currency'];
            $description = match (data_get($spec, 'voucher.type')) {
                'fixed' => MoneyFormatter::formatMinor($value, $currency),
                'percentage' => number_format($value / 100, 2).'%',
                default => 'mengikut terma program',
            };

            return 'Baucar '.$description.' (sekali guna)';
        }

        return 'Ganjaran mengikut terma program.';
    }

    /** @return Collection<int, ReferralInvite> */
    #[Computed]
    public function myInvites(): Collection
    {
        $user = $this->currentUser();

        return ReferralInvite::query()
            ->where('referrer_type', $user->getMorphClass())
            ->where('referrer_id', (string) $user->getKey())
            ->with('program')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    public function render(): View
    {
        return view('livewire.pages.dashboard.referral-member-index');
    }

    private function currentUser(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    /** @return list<string> */
    private function verifiedContacts(User $user): array
    {
        $contacts = [];

        if ($user->email_verified_at !== null && is_string($user->email) && trim($user->email) !== '') {
            $contacts[] = trim($user->email);
        }

        if ($user->phone_verified_at !== null && is_string($user->phone) && trim($user->phone) !== '') {
            $contacts[] = trim($user->phone);
        }

        return $contacts;
    }
}
