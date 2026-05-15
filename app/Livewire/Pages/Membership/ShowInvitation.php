<?php

namespace App\Livewire\Pages\Membership;

use App\Actions\Contributions\ResolveContributionSubjectPresentationAction;
use App\Actions\Membership\AcceptSubjectMemberInvitation;
use App\Actions\Membership\ResolveMemberInvitationByTokenAction;
use App\Enums\MemberSubjectType;
use App\Models\Event;
use App\Models\Institution;
use App\Models\MemberInvitation;
use App\Models\Reference;
use App\Models\Speaker;
use App\Models\User;
use App\Support\Authz\MemberRoleCatalog;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RuntimeException;

#[Layout('layouts.app')]
class ShowInvitation extends Component
{
    public MemberInvitation $invitation;

    public Event|Institution|Reference|Speaker $subject;

    /** @var array{subject_label: string, redirect_url: string} */
    public array $subjectPresentation = [
        'subject_label' => '',
        'redirect_url' => '',
    ];

    public string $subjectName = '';

    public string $roleLabel = '';

    public bool $subjectUnavailable = false;

    public function mount(
        string $token,
        ResolveMemberInvitationByTokenAction $resolveMemberInvitationByTokenAction,
        ResolveContributionSubjectPresentationAction $resolveContributionSubjectPresentationAction,
        MemberRoleCatalog $memberRoleCatalog,
    ): void {
        abort_unless(auth()->user() instanceof User, 403);

        $this->invitation = $resolveMemberInvitationByTokenAction->handle($token);

        $subjectType = $this->invitation->subject_type;

        if (! $subjectType instanceof MemberSubjectType) {
            throw new RuntimeException('Invitation subject type is not valid.');
        }

        $this->roleLabel = $memberRoleCatalog->roleLabel($subjectType, $this->invitation->role_slug);

        try {
            $this->subject = $subjectType->resolveSubject($this->invitation->subject_id);
            $this->subjectPresentation = $resolveContributionSubjectPresentationAction->handle($this->subject);
            $this->subjectName = $this->resolveSubjectName($this->subject);
        } catch (ModelNotFoundException) {
            $this->subjectUnavailable = true;
            $this->subjectPresentation = [
                'subject_label' => $this->subjectLabel($subjectType),
                'redirect_url' => route('home'),
            ];
            $this->subjectName = __('Unavailable :subject', [
                'subject' => strtolower($this->subjectPresentation['subject_label']),
            ]);
        }
    }

    #[Computed]
    public function acceptanceError(): ?string
    {
        return $this->resolveAcceptanceError();
    }

    #[Computed]
    public function canAccept(): bool
    {
        return $this->resolveAcceptanceError() === null;
    }

    public function accept(AcceptSubjectMemberInvitation $acceptSubjectMemberInvitation): void
    {
        /** @var User $user */
        $user = auth()->user();

        $acceptSubjectMemberInvitation->handle($this->invitation->fresh() ?? $this->invitation, $user);

        session()->flash('success', __('Invitation accepted.'));

        $this->redirect($this->subjectPresentation['redirect_url'], navigate: true);
    }

    public function rendering(object $view): void
    {
        if (method_exists($view, 'title')) {
            $view->title(__('Member Invitation').' - '.config('app.name'));
        }
    }

    private function resolveAcceptanceError(): ?string
    {
        /** @var User $user */
        $user = auth()->user();

        if ($this->invitation->isAccepted()) {
            return __('This invitation has already been accepted.');
        }

        if ($this->invitation->isRevoked()) {
            return __('This invitation has been revoked.');
        }

        if ($this->invitation->isExpired()) {
            return __('This invitation has expired.');
        }

        $subjectType = $this->invitation->subject_type;

        if (! $subjectType instanceof MemberSubjectType || ! app(MemberRoleCatalog::class)->isInvitableRole($subjectType, $this->invitation->role_slug)) {
            return __('This invitation is no longer valid.');
        }

        if ($this->subjectUnavailable) {
            return __('This invitation is no longer valid.');
        }

        $userEmail = is_string($user->email) ? trim($user->email) : '';

        if ($userEmail === '') {
            return __('Add an email address to your account before accepting this invitation.');
        }

        if (mb_strtolower($userEmail) !== mb_strtolower($this->invitation->email)) {
            return __('This invitation was sent to :email, but you are signed in as :current.', [
                'email' => $this->invitation->email,
                'current' => $userEmail,
            ]);
        }

        return null;
    }

    private function resolveSubjectName(Event|Institution|Reference|Speaker $subject): string
    {
        return match (true) {
            $subject instanceof Event => $subject->title,
            $subject instanceof Reference => $subject->title,
            default => $subject->name,
        };
    }

    private function subjectLabel(MemberSubjectType $subjectType): string
    {
        return match ($subjectType) {
            MemberSubjectType::Institution => __('Institution'),
            MemberSubjectType::Speaker => __('Speaker'),
            MemberSubjectType::Reference => __('Reference'),
            MemberSubjectType::Event => __('Event'),
        };
    }
}
