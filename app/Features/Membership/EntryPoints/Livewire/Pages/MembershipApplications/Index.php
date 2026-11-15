<?php

declare(strict_types=1);

namespace App\Features\Membership\EntryPoints\Livewire\Pages\MembershipApplications;

use App\Features\Membership\Api\MembershipApplicationDetail;
use App\Features\Membership\Api\MembershipApplications;
use App\Platform\Ui\InteractsWithToasts;
use App\Shared\Auth\AccountActor;
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
        $this->accountId();
    }

    /**
     * @return Collection<int, MembershipApplicationDetail>
     */
    #[Computed]
    public function myApplications(): Collection
    {
        return collect(app(MembershipApplications::class)->forApplicant($this->accountId()));
    }

    public function cancel(string $applicationId, MembershipApplications $membershipApplications): void
    {
        try {
            $detail = $membershipApplications->cancelForApplicant($this->accountId(), $applicationId);
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== 'Only pending membership applications can be cancelled.') {
                throw $exception;
            }

            $this->errorToast(__('Only pending claims can be cancelled.'));

            return;
        }

        abort_if(! $detail instanceof MembershipApplicationDetail, 404);

        $this->successToast(__('Membership claim cancelled.'));
    }

    public function render(): View
    {
        return view('membership::livewire.pages.membership-applications.index');
    }

    private function accountId(): string
    {
        $account = auth()->user();
        abort_unless($account instanceof AccountActor, 403);

        return $account->actorId();
    }
}
