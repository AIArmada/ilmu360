<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Dashboard\Organizations;

use AIArmada\Organizations\Models\Organization;
use App\Models\Institution;
use App\Models\Person;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Workspaces')]
final class Index extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user() instanceof User, 403);
    }

    /** @return Collection<int, Organization> */
    public function organizations(): Collection
    {
        $user = $this->currentUser();

        return Organization::query()
            ->whereHas('members', fn (Builder $query): Builder => $query->whereKey($user->getKey()))
            ->withCount('members')
            ->orderBy('name')
            ->get();
    }

    /** @return Collection<int, Institution> */
    public function institutions(): Collection
    {
        $user = $this->currentUser();

        return Institution::query()
            ->whereHas('members', fn (Builder $query): Builder => $query->whereKey($user->getKey()))
            ->withCount('members')
            ->orderBy('name')
            ->get();
    }

    /** @return Collection<int, Person> */
    public function persons(): Collection
    {
        $user = $this->currentUser();

        return Person::query()
            ->whereHas('members', fn (Builder $query): Builder => $query->whereKey($user->getKey()))
            ->withCount('members')
            ->orderBy('name')
            ->get();
    }

    public function render(): View
    {
        return view('livewire.pages.dashboard.organizations.index', [
            'organizations' => $this->organizations(),
            'institutions' => $this->institutions(),
            'persons' => $this->persons(),
        ]);
    }

    private function currentUser(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
