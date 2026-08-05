<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Dashboard\Organizations;

use AIArmada\Organizations\Models\Organization;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Organizations')]
final class Index extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user() instanceof User, 403);
    }

    /** @return Collection<int, Organization> */
    public function organizations(): Collection
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return Organization::query()
            ->whereHas('members', fn (Builder $query): Builder => $query->whereKey($user->getKey()))
            ->withCount('members')
            ->orderBy('name')
            ->get();
    }

    public function render(): View
    {
        return view('livewire.pages.dashboard.organizations.index', [
            'organizations' => $this->organizations(),
        ]);
    }
}
