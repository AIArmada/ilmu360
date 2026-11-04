<?php

namespace App\Livewire\Components;

use App\Actions\ToggleDirectoryFollowAction;
use App\Models\User;
use App\Support\Auth\IntendedRedirect;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class DirectoryCardFollow extends Component
{
    #[Locked]
    public string $subjectType;

    #[Locked]
    public string $subjectId;

    #[Locked]
    public string $returnUrl;

    #[Locked]
    public bool $refreshListing = false;

    public bool $isFollowing = false;

    public function toggleFollow(string $subjectId): void
    {
        abort_unless($subjectId === $this->subjectId, 403);
        $user = auth()->user();

        if (! $user instanceof User) {
            $this->redirect(IntendedRedirect::loginUrl($this->returnUrl), navigate: true);

            return;
        }

        $state = app(ToggleDirectoryFollowAction::class)->handle($this->subjectType, $subjectId, $user);

        if ($state === null) {
            return;
        }

        $this->isFollowing = $state;

        if ($this->refreshListing) {
            $this->dispatch("directory-follow-updated.{$this->subjectType}");
        }
    }

    public function render(): View
    {
        return view('livewire.components.directory-card-follow', [
            'followIcon' => $this->subjectType === 'person' ? 'speaker' : $this->subjectType,
        ]);
    }
}
