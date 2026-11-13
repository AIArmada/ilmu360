<?php

namespace App\Livewire\Components;

use App\Actions\Engagement\FollowSubjectAction;
use App\Actions\Engagement\UnfollowSubjectAction;
use App\Models\User;
use App\Shared\Auth\IntendedRedirect;
use App\Support\Engagement\DirectoryFollowSubjectResolver;
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

        $subject = app(DirectoryFollowSubjectResolver::class)->resolve($this->subjectType, $subjectId);

        if ($subject === null) {
            return;
        }

        if ($user->isFollowing($subject)) {
            app(UnfollowSubjectAction::class)->handle($subject, $user);
            $this->isFollowing = false;
        } else {
            app(FollowSubjectAction::class)->handle(
                subject: $subject,
                user: $user,
                metadata: ["{$this->subjectType}_id" => $subject->getKey()],
                request: request(),
            );
            $this->isFollowing = true;
        }

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
