<?php

namespace App\Livewire\Pages\Membership;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Membership\Actions\AcceptInvitationAction;
use AIArmada\Membership\Enums\MemberRole;
use App\Enums\MemberSubjectType;
use App\Livewire\Concerns\InteractsWithToasts;
use App\Models\Event;
use App\Models\Institution;
use App\Models\MemberInvitation;
use App\Models\Reference;
use App\Models\Speaker;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RuntimeException;

#[Layout('layouts.app')]
class ShowInvitation extends Component
{
    use InteractsWithToasts;

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
    ): void {
        abort_unless(auth()->user() instanceof User, 403);

        $this->invitation = $this->resolveInvitationByToken($token);

        $subjectType = $this->invitation->subject_type;

        if (! $subjectType instanceof MemberSubjectType) {
            throw new RuntimeException('Invitation subject type is not valid.');
        }

        $this->roleLabel = MemberRole::tryFrom($this->invitation->role)?->label() ?? $this->invitation->role;

        try {
            $this->subject = $subjectType->resolveSubject($this->invitation->subject_id);
            $this->subjectPresentation = $this->resolveSubjectPresentation($this->subject);
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

    public function accept(AcceptInvitationAction $acceptInvitationAction): void
    {
        /** @var User $user */
        $user = auth()->user();

        $acceptanceError = $this->acceptanceError();

        if ($acceptanceError !== null) {
            $this->errorToast($acceptanceError);

            return;
        }

        try {
            OwnerContext::withOwner(null, fn (): null => $acceptInvitationAction->handle(
                $this->invitation->fresh() ?? $this->invitation,
                $user,
            ));
        } catch (RuntimeException) {
            $this->errorToast(__('This invitation is no longer valid.'));

            return;
        }

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

        if (! $subjectType instanceof MemberSubjectType || MemberRole::tryFrom($this->invitation->role) === null) {
            return __('This invitation is no longer valid.');
        }

        if ($this->invitation->role === MemberRole::Owner->value) {
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

    private function resolveInvitationByToken(string $token): MemberInvitation
    {
        $invitation = MemberInvitation::query()
            ->whereIn('token', [$token, MemberInvitation::tokenForStorage($token)])
            ->first();

        abort_unless($invitation instanceof MemberInvitation, 404);

        return $invitation;
    }

    /**
     * @return array{subject_label: string, redirect_url: string}
     */
    private function resolveSubjectPresentation(Event|Institution|Reference|Speaker $subject): array
    {
        return [
            'subject_label' => match (true) {
                $subject instanceof Event => __('Event'),
                $subject instanceof Institution => __('Institution'),
                $subject instanceof Speaker => __('Speaker'),
                $subject instanceof Reference => __('Reference'),
            },
            'redirect_url' => match (true) {
                $subject instanceof Event => route('events.show', $subject),
                $subject instanceof Institution => route('institutions.show', $subject),
                $subject instanceof Speaker => route('speakers.show', $subject),
                $subject instanceof Reference => route('references.show', $subject),
            },
        ];
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
