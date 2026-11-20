<?php

namespace App\Features\Engagement\EntryPoints\Livewire\Components;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Features\Engagement\Api\EngagementSubjectType;
use App\Features\Engagement\Api\SubjectFollowCommands;
use App\Features\Engagement\Api\SubjectFollows;
use App\Features\Engagement\Services\DirectoryFollowSubjectResolver;
use App\Models\User;
use App\Shared\Auth\IntendedRedirect;
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

        $isFollowing = OwnerContext::withOwner(null, fn (): bool => app(SubjectFollows::class)
            ->followStateFor(
                (string) $user->getKey(),
                EngagementSubjectType::from($this->subjectType),
                $subject->id,
            )
            ->isFollowing);

        if ($isFollowing) {
            app(SubjectFollowCommands::class)->unfollow($subject->morphType, $subject->id, (string) $user->getKey());
            $this->isFollowing = false;
        } else {
            app(SubjectFollowCommands::class)->follow(
                $subject->morphType,
                $subject->id,
                (string) $user->getKey(),
                ["{$this->subjectType}_id" => $subject->id],
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
