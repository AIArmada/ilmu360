<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Dashboard\Organizations;

use App\Features\Organizations\Api\OrganizationCommands;
use App\Models\User;
use App\Shared\Membership\MembershipDirectory;
use App\Shared\Membership\MembershipSubjectType;
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
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        if ($this->hasOrganizationMembership($user)) {
            $this->redirectRoute('dashboard.organizations.index');

            return;
        }
    }

    public function submit(OrganizationCommands $organizationCommands): mixed
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        if ($this->hasOrganizationMembership($user)) {
            return redirect()->route('dashboard.organizations.index');
        }

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);

        $organization = $organizationCommands->create((string) $user->getKey(), $validated);

        return redirect()->route('dashboard.organizations.show', $organization->id);
    }

    private function hasOrganizationMembership(User $user): bool
    {
        return app(MembershipDirectory::class)->subjectIdsFor(
            (string) $user->getKey(),
            MembershipSubjectType::Organization,
        ) !== [];
    }

    public function render(): View
    {
        return view('livewire.pages.dashboard.organizations.create-organization');
    }
}
