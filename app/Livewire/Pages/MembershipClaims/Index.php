<?php

namespace App\Livewire\Pages\MembershipClaims;

use App\Actions\Membership\CancelMembershipClaimAction;
use App\Livewire\Concerns\InteractsWithToasts;
use App\Models\MembershipClaim;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use RuntimeException;

#[Layout('layouts.app')]
#[Title('My Membership Claims')]
class Index extends Component
{
    use InteractsWithToasts;

    public function mount(): void
    {
        abort_unless(auth()->user() instanceof User, 403);
    }

    /**
     * @return Collection<int, MembershipClaim>
     */
    #[Computed]
    public function myClaims(): Collection
    {
        /** @var User $user */
        $user = auth()->user();

        return $user->membershipClaims()
            ->with(['reviewer'])
            ->latest('created_at')
            ->get();
    }

    public function cancel(string $claimId, CancelMembershipClaimAction $cancelMembershipClaimAction): void
    {
        /** @var User $user */
        $user = auth()->user();

        $claim = $user->membershipClaims()->whereKey($claimId)->first();
        abort_unless($claim instanceof MembershipClaim, 404);

        try {
            $cancelMembershipClaimAction->handle($claim, $user);
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== 'membership_claim_cannot_cancel') {
                throw $exception;
            }

            $this->errorToast(__('Only pending claims can be cancelled.'));

            return;
        }

        $this->successToast(__('Membership claim cancelled.'));
    }

    public function render(): View
    {
        return view('livewire.pages.membership-claims.index');
    }
}
