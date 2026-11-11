<?php

namespace App\Livewire\Pages\MembershipApplications;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Features\Membership\Api\MembershipApplications;
use App\Features\Membership\Api\MembershipApplicationSummary;
use App\Models\MembershipApplication;
use App\Models\User;
use App\Platform\Ui\InteractsWithToasts;
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
        $applicationIds = array_map(
            static fn (MembershipApplicationSummary $summary): string => $summary->id,
            app(MembershipApplications::class)->forApplicant((string) $user->getKey()),
        );

        return OwnerContext::withOwner(null, fn (): Collection => $applicationIds === []
            ? new Collection
            : MembershipApplication::query()
                ->whereKey($applicationIds)
                ->with(['reviewer'])
                ->latest('created_at')
                ->get());
    }

    public function cancel(string $claimId, MembershipApplications $membershipApplications): void
    {
        /** @var User $user */
        $user = auth()->user();

        try {
            $detail = $membershipApplications->cancelForApplicant((string) $user->getKey(), $claimId);
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== 'Only pending membership applications can be cancelled.') {
                throw $exception;
            }

            $this->errorToast(__('Only pending claims can be cancelled.'));

            return;
        }

        abort_if($detail === null, 404);

        $this->successToast(__('Membership claim cancelled.'));
    }

    public function render(): View
    {
        return view('livewire.pages.membership-applications.index');
    }
}
