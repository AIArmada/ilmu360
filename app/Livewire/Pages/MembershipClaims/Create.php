<?php

namespace App\Livewire\Pages\MembershipClaims;

use App\Actions\Membership\ResolveMembershipClaimSubjectAction;
use App\Actions\Membership\ResolveMembershipClaimSubjectPresentationAction;
use App\Actions\Membership\SubmitMembershipClaimAction;
use App\Enums\MemberSubjectType;
use App\Livewire\Concerns\InteractsWithToasts;
use App\Models\Institution;
use App\Models\MembershipClaim;
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
        ResolveMembershipClaimSubjectAction $resolveMembershipClaimSubjectAction,
        ResolveMembershipClaimSubjectPresentationAction $resolveMembershipClaimSubjectPresentationAction,
    ): void {
        $resolvedSubjectType = MemberSubjectType::fromRouteSegment($subjectType);

        abort_unless($resolvedSubjectType?->isClaimable(), 404);

        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        if (! $user->canSubmitDirectoryFeedback()) {
            abort(403, $user->directoryFeedbackBanMessage());
        }

        $this->subjectType = $resolvedSubjectType->value;
        $this->subject = $resolveMembershipClaimSubjectAction->handle($subjectType, $subjectId);
        $this->context = $resolveMembershipClaimSubjectPresentationAction->handle($this->subject);

        if ($this->shouldRedirectToCanonicalSubjectUrl($resolvedSubjectType, $subjectId)) {
            $this->redirectRoute('membership-claims.create', [
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
            ->model(new MembershipClaim)
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

    public function submit(SubmitMembershipClaimAction $submitMembershipClaimAction): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        if (! $user->canSubmitDirectoryFeedback()) {
            abort(403, $user->directoryFeedbackBanMessage());
        }

        $state = $this->claimForm()->getState();

        try {
            $claim = $submitMembershipClaimAction->handle(
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

        $this->redirect(route('membership-claims.index'), navigate: true);
    }

    public function rendering(object $view): void
    {
        if (method_exists($view, 'title')) {
            $view->title(__('Claim Membership').' - '.config('app.name'));
        }
    }

    protected function claimForm(): Schema
    {
        return $this->getForm('form') ?? throw new RuntimeException('Membership claim form is not available.');
    }

    private function canonicalSubjectId(): string
    {
        return $this->subject->slug;
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
