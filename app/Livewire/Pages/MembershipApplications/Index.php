<?php

namespace App\Livewire\Pages\MembershipApplications;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Membership\Actions\CancelMembershipApplicationAction;
use App\Models\MembershipApplication;
use App\Models\User;
use App\Platform\Ui\InteractsWithToasts;
use App\Shared\Membership\MembershipApplicationReader;
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
        $applicationIds = app(MembershipApplicationReader::class)->applicationIdsForApplicant((string) $user->getKey());

        return OwnerContext::withOwner(null, fn (): Collection => $applicationIds === []
            ? new Collection
            : MembershipApplication::query()
                ->whereKey($applicationIds)
                ->with(['reviewer'])
                ->latest('created_at')
                ->get());
    }

    public function cancel(string $claimId, CancelMembershipApplicationAction $cancelMembershipApplicationAction): void
    {
        /** @var User $user */
        $user = auth()->user();
        $applicationId = app(MembershipApplicationReader::class)->findApplicationIdForApplicant(
            (string) $user->getKey(),
            $claimId,
        );

        $claim = OwnerContext::withOwner(null, fn (): ?MembershipApplication => $applicationId === null
            ? null
            : MembershipApplication::query()->whereKey($applicationId)->first());
        abort_unless($claim instanceof MembershipApplication, 404);

        try {
            OwnerContext::withOwner(null, function () use ($cancelMembershipApplicationAction, $claim): void {
                $cancelMembershipApplicationAction->handle($claim);
            });
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
