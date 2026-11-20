<?php

declare(strict_types=1);

namespace App\Features\Organizations\EntryPoints\Livewire\Pages;

use AIArmada\Organizations\Models\Organization;
use App\Features\Institutions\Api\Orm\InstitutionLookup;
use App\Features\Institutions\Models\Institution;
use App\Features\Persons\Api\Orm\PersonLookup;
use App\Features\Persons\Models\Person;
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

        /** @var Collection<int, Institution> $institutions */
        $institutions = app(InstitutionLookup::class)->institutionsForMember((string) $user->getKey());

        return $institutions;
    }

    /** @return Collection<int, Person> */
    public function persons(): Collection
    {
        $user = $this->currentUser();

        /** @var Collection<int, Person> $persons */
        $persons = app(PersonLookup::class)->personsForMember((string) $user->getKey());

        return $persons;
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
