<?php

namespace App\Livewire\Pages\MembershipApplications;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Membership\Actions\CancelMembershipApplicationAction;
use App\Livewire\Concerns\InteractsWithToasts;
use App\Models\MembershipApplication;
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
     * @return Collection<int, MembershipApplication>
     */
    #[Computed]
    public function myClaims(): Collection
    {
        /** @var User $user */
        $user = auth()->user();

        return OwnerContext::withOwner(null, fn (): Collection => $user->membershipApplications()
            ->with(['reviewer'])
            ->latest('created_at')
            ->get());
    }

    public function cancel(string $claimId, CancelMembershipApplicationAction $cancelMembershipApplicationAction): void
    {
        /** @var User $user */
        $user = auth()->user();

        $claim = OwnerContext::withOwner(null, fn (): ?MembershipApplication => $user->membershipApplications()
            ->whereKey($claimId)
            ->first());
        abort_unless($claim instanceof MembershipApplication, 404);

        try {
            $cancelMembershipApplicationAction->handle($claim);
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== 'Only pending membership applications can be cancelled.') {
                throw $exception;
            }

            $this->errorToast(__('Only pending claims can be cancelled.'));

            return;
        }

        $this->successToast(__('Membership claim cancelled.'));
    }

    public function render(): View
    {
        return view('livewire.pages.membership-applications.index');
    }
}
