<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Dashboard\Organizations;

use AIArmada\Organizations\Actions\CreateOrganizationAction;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Create organization')]
final class CreateOrganization extends Component
{
    public string $name = '';

    public string $description = '';

    public function mount(): void
    {
        abort_unless(auth()->user() instanceof User, 403);
    }

    public function submit(CreateOrganizationAction $createOrganization): mixed
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);

        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $organization = $createOrganization->handle($user, $validated);

        return redirect()->route('dashboard.organizations.show', $organization);
    }

    public function render(): View
    {
        return view('livewire.pages.dashboard.organizations.create-organization');
    }
}
