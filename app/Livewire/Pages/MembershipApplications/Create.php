<?php

namespace App\Livewire\Pages\MembershipApplications;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Actions\Membership\SubmitMembershipApplicationAction;
use App\Enums\MemberSubjectType;
use App\Livewire\Concerns\InteractsWithToasts;
use App\Models\Institution;
use App\Models\MembershipApplication;
use App\Models\Speaker;
use App\Models\User;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;
use RuntimeException;

#[Layout('layouts.app')]
class Create extends Component implements HasForms
{
    use InteractsWithForms;
    use InteractsWithToasts;
    use WithFileUploads;

    public function boot(): void
    {
        OwnerContext::setForRequest(null);
    }

    public Institution|Speaker $subject;

    public string $subjectType;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var array{subject_label: string, subject_title: string, redirect_url: string, admin_url: string} */
    public array $context = [
        'subject_label' => '',
        'subject_title' => '',
        'redirect_url' => '',
        'admin_url' => '',
    ];

    public function mount(
        string $subjectType,
        string $subjectId,
    ): void {
        $resolvedSubjectType = MemberSubjectType::fromRouteSegment($subjectType);

        abort_unless($resolvedSubjectType?->isClaimable(), 404);

        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        if (! $user->canSubmitDirectoryFeedback()) {
            abort(403, $user->directoryFeedbackBanMessage());
        }

        $this->subjectType = $resolvedSubjectType->value;
        $this->subject = $this->resolveSubject($subjectType, $subjectId);
        $this->context = $this->resolveSubjectPresentation($this->subject);

        if ($this->shouldRedirectToCanonicalSubjectUrl($resolvedSubjectType, $subjectId)) {
            $this->redirectRoute('membership-applications.create', [
                'subjectType' => $resolvedSubjectType->publicRouteSegment(),
                'subjectId' => $this->canonicalSubjectId(),
            ], navigate: true);

            return;
        }

        $this->claimForm()->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->model(new MembershipApplication)
            ->statePath('data')
            ->components([
                Section::make(__('Claim membership for this :subject', ['subject' => strtolower($this->context['subject_label'])]))
                    ->description(__('Explain your connection to this record so moderators can verify it.'))
                    ->schema([
                        Textarea::make('justification')
                            ->label(__('Why should you be added?'))
                            ->rows(6)
                            ->required()
                            ->maxLength(2000)
                            ->helperText(__('Describe your role, relationship, and any context that helps reviewers verify your claim.'))
                            ->columnSpanFull(),
                        SpatieMediaLibraryFileUpload::make('evidence')
                            ->label(__('Evidence Files'))
                            ->collection('evidence')
                            ->multiple()
                            ->reorderable()
                            ->required()
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                            ->maxFiles(8)
                            ->conversion('thumb')
                            ->openable()
                            ->downloadable()
                            ->helperText(__('Upload screenshots, letters, profile pages, or PDFs that support your claim.'))
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public function submit(SubmitMembershipApplicationAction $submitMembershipApplicationAction): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        if (! $user->canSubmitDirectoryFeedback()) {
            abort(403, $user->directoryFeedbackBanMessage());
        }

        $state = $this->claimForm()->getState();

        try {
            $claim = $submitMembershipApplicationAction->handle(
                $this->subject,
                $user,
                (string) ($state['justification'] ?? ''),
            );
        } catch (RuntimeException $exception) {
            match ($exception->getMessage()) {
                'membership_claim_already_member' => $this->addError('data.justification', __('You are already a member of this record.')),
                'membership_claim_duplicate_pending' => $this->addError('data.justification', __('You already have a pending claim for this record.')),
                'membership_claim_pending_invitation' => $this->addError('data.justification', __('You already have a pending invitation for this record. Please accept that invitation instead.')),
                default => throw $exception,
            };

            return;
        }

        $this->claimForm()->model($claim)->saveRelationships();

        $this->successToast(__('Membership claim submitted for review.'));

        $this->redirect(route('membership-applications.index'), navigate: true);
    }

    public function rendering(object $view): void
    {
        if (method_exists($view, 'title')) {
            $view->title(__('Claim Membership').' - '.config('app.name'));
        }
    }

    protected function claimForm(): Schema
    {
        return $this->getForm('form') ?? throw new RuntimeException('Membership application form is not available.');
    }

    private function canonicalSubjectId(): string
    {
        return (string) $this->subject->getKey();
    }

    private function resolveSubject(string $subjectType, string $subjectId): Institution|Speaker
    {
        $resolvedSubjectType = MemberSubjectType::fromRouteSegment($subjectType);

        abort_unless($resolvedSubjectType instanceof MemberSubjectType, 404);

        return $resolvedSubjectType->resolveSubject($subjectId);
    }

    /**
     * @return array{subject_label: string, subject_title: string, redirect_url: string, admin_url: string}
     */
    private function resolveSubjectPresentation(Institution|Speaker $subject): array
    {
        $subjectType = $subject instanceof Institution ? MemberSubjectType::Institution : MemberSubjectType::Speaker;

        return [
            'subject_label' => $subjectType->label(),
            'subject_title' => $subject instanceof Institution ? $subject->name : $subject->formatted_name,
            'redirect_url' => $subject instanceof Institution
                ? route('institutions.show', $subject)
                : route('speakers.show', $subject),
            'admin_url' => '',
        ];
    }

    private function shouldRedirectToCanonicalSubjectUrl(MemberSubjectType $subjectType, string $subjectId): bool
    {
        $routeSubjectType = request()->route('subjectType');

        if (! is_string($routeSubjectType) || $routeSubjectType === '') {
            return false;
        }

        return $subjectType->publicRouteSegment() !== $routeSubjectType
            || $this->canonicalSubjectId() !== $subjectId;
    }
}
