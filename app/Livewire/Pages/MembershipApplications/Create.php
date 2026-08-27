<?php

namespace App\Livewire\Pages\MembershipApplications;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Membership\Enums\MemberRole;
use App\Actions\Membership\SubmitMembershipApplicationAction;
use App\Enums\MemberSubjectType;
use App\Livewire\Concerns\InteractsWithToasts;
use App\Models\Institution;
use App\Models\MembershipApplication;
use App\Models\Person;
use App\Models\User;
use App\Support\Membership\MembershipApplicationPresenter;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;
use RuntimeException;
use Ysfkaya\FilamentPhoneInput\Forms\PhoneInput;
use Ysfkaya\FilamentPhoneInput\PhoneInputNumberType;

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

    public Institution|Person $subject;

    public string $subjectType;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var array{subject_label: string, subject_title: string, redirect_url: string, admin_url: string, profile_image_url: string|null} */
    public array $context = [
        'subject_label' => '',
        'subject_title' => '',
        'redirect_url' => '',
        'admin_url' => '',
        'profile_image_url' => null,
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
        $user = auth()->user();
        $needsPhone = $user instanceof User && blank($user->phone);
        $subjectType = MemberSubjectType::tryFrom($this->subjectType);

        return $schema
            ->model(new MembershipApplication)
            ->statePath('data')
            ->components([
                Section::make(__('Claim membership for this :subject', ['subject' => strtolower($this->context['subject_label'])]))
                    ->description(__('Tell us how you are connected to this record so moderators can verify your claim.'))
                    ->schema([
                        Select::make('applied_role')
                            ->label('Peranan yang anda mohon')
                            ->options([
                                MemberRole::Owner->value => 'Pemilik',
                                MemberRole::Admin->value => 'Pentadbir',
                                MemberRole::Editor->value => 'Editor',
                            ])
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set, ?string $state) use ($subjectType): void {
                                if ($subjectType !== MemberSubjectType::Institution && $state === MemberRole::Owner->value) {
                                    $set('relationship', 'self');
                                }
                            }),
                        Select::make('relationship')
                            ->label('Hubungan anda dengan '.$this->context['subject_label'])
                            ->options(MembershipApplicationPresenter::relationshipOptions($subjectType))
                            ->required()
                            ->default($subjectType === MemberSubjectType::Institution ? null : 'self')
                            ->disabled(fn (Get $get): bool => $subjectType !== MemberSubjectType::Institution && $get('applied_role') === MemberRole::Owner->value),
                        PhoneInput::make('phone')
                            ->label(__('Phone Number'))
                            ->initialCountry('MY')
                            ->displayNumberFormat(PhoneInputNumberType::INTERNATIONAL)
                            ->inputNumberFormat(PhoneInputNumberType::E164)
                            ->required()
                            ->visible($needsPhone)
                            ->helperText(__('We need a contact number to verify your claim. This is saved to your profile, not the application.'))
                            ->columnSpanFull(),
                        Textarea::make('notes')
                            ->label(__('Catatan'))
                            ->placeholder(__('Tulis apa-apa maklumat tambahan yang berkaitan dengan permohonan anda.'))
                            ->helperText(__('Catatan ini akan dibaca oleh penyemak bersama bukti yang anda hantar.'))
                            ->rows(4)
                            ->maxLength(2000)
                            ->columnSpanFull(),
                        SpatieMediaLibraryFileUpload::make('evidence')
                            ->label(__('Evidence Files'))
                            ->collection('evidence')
                            ->multiple()
                            ->reorderable()
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                            ->maxFiles(8)
                            ->conversion('thumb')
                            ->openable()
                            ->downloadable()
                            ->helperText(__('Optional. Upload screenshots, letters, profile pages, or PDFs that support your claim.'))
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

        $appliedRole = $state['applied_role'] instanceof MemberRole
            ? $state['applied_role']->value
            : (string) ($state['applied_role'] ?? MemberRole::Editor->value);
        $subjectType = MemberSubjectType::tryFrom($this->subjectType);
        $relationship = (string) ($state['relationship'] ?? ($subjectType === MemberSubjectType::Institution ? '' : 'self'));
        $notes = trim((string) ($state['notes'] ?? ''));

        if (filled($state['phone'] ?? null) && blank($user->phone)) {
            $user->update(['phone' => $state['phone']]);
        }

        $justification = sprintf(
            'Applying as %s. Relationship: %s.',
            MemberRole::from($appliedRole)->label(),
            MembershipApplicationPresenter::relationshipOptions($subjectType)[$relationship] ?? $relationship,
        );

        $meta = [
            'applied_role' => $appliedRole,
            'relationship' => $relationship,
            'notes' => $notes !== '' ? $notes : null,
        ];

        try {
            $claim = $submitMembershipApplicationAction->handle(
                $this->subject,
                $user,
                $justification,
                $meta,
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
        return $this->subject instanceof Person
            ? (string) $this->subject->slug
            : (string) $this->subject->getKey();
    }

    private function resolveSubject(string $subjectType, string $subjectId): Institution|Person
    {
        $resolvedSubjectType = MemberSubjectType::fromRouteSegment($subjectType);

        abort_unless($resolvedSubjectType instanceof MemberSubjectType, 404);

        return $resolvedSubjectType === MemberSubjectType::Person
            ? Person::query()->where('slug', $subjectId)->firstOrFail()
            : Institution::query()->findOrFail($subjectId);
    }

    /**
     * @return array{subject_label: string, subject_title: string, redirect_url: string, admin_url: string, profile_image_url: string|null}
     */
    private function resolveSubjectPresentation(Institution|Person $subject): array
    {
        $subjectType = $subject instanceof Institution ? MemberSubjectType::Institution : MemberSubjectType::Person;

        return [
            'subject_label' => $subjectType->label(),
            'subject_title' => $subject instanceof Institution ? $subject->name : $subject->formatted_name,
            'redirect_url' => $subject instanceof Institution
                ? route('institutions.show', $subject)
                : route('persons.show', $subject),
            'admin_url' => '',
            'profile_image_url' => $subject instanceof Person
                ? ($subject->hasMedia('profile')
                    ? $subject->public_main_url
                    : ($subject->hasMedia('avatar') ? $subject->public_avatar_url : null))
                : null,
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
