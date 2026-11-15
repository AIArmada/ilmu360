<?php

declare(strict_types=1);

namespace App\Features\Membership\EntryPoints\Livewire\Pages\MembershipApplications;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Membership\Enums\MemberRole;
use App\Features\Directory\Api\DirectoryDetail;
use App\Features\Directory\Api\DirectoryReader;
use App\Features\Directory\Api\DirectoryRecordType;
use App\Features\Identity\Api\AccountAdministration;
use App\Features\Membership\Api\MembershipApplicationFormRules;
use App\Features\Membership\Api\MembershipApplications;
use App\Features\Membership\Api\MembershipApplicationSubjectType;
use App\Features\Membership\Api\SubmitMembershipApplicationInput;
use App\Platform\Media\StagedUploadFactory;
use App\Platform\Ui\InteractsWithToasts;
use App\Shared\Auth\AccountDirectory;
use App\Shared\Directory\Enums\InstitutionType;
use App\Shared\Feedback\FeedbackActor;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
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

    public string $subjectType;

    public string $subjectId;

    public ?string $subjectSlug = null;

    public ?string $institutionTypeValue = null;

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
        DirectoryReader $directoryReader,
    ): void {
        $resolvedSubjectType = MembershipApplicationSubjectType::fromPublicRouteSegment($subjectType);

        abort_unless($resolvedSubjectType instanceof MembershipApplicationSubjectType, 404);

        $user = auth()->user();
        abort_unless($user instanceof FeedbackActor, 403);

        if (! $user->canSubmitDirectoryFeedback()) {
            abort(403, $user->directoryFeedbackBanMessage());
        }

        $this->subjectType = $resolvedSubjectType->value;
        $subject = $this->resolveSubject($resolvedSubjectType, $subjectId, $directoryReader);
        $this->subjectId = $subject->id;
        $this->subjectSlug = $subject->slug;
        $this->institutionTypeValue = $subject->institutionType;
        $this->context = $this->resolveSubjectPresentation($resolvedSubjectType, $subject);

        if ($this->shouldRedirectToCanonicalSubjectUrl($resolvedSubjectType, $subjectId)) {
            $this->redirectRoute('membership-applications.create', [
                'subjectType' => $resolvedSubjectType->publicRouteSegment(),
                'subjectId' => $this->canonicalSubjectId(),
            ], navigate: true);

            return;
        }

        $this->applicationForm()->fill();
    }

    public function form(Schema $schema): Schema
    {
        $subjectType = MembershipApplicationSubjectType::tryFrom($this->subjectType);
        $institutionType = $this->subjectInstitutionType();
        $relationshipOptions = MembershipApplicationFormRules::relationshipOptions(
            $subjectType?->membershipSubjectType(),
            $institutionType,
        );
        $applicationRoleOptions = MembershipApplicationFormRules::applicationRoleOptions();
        $accountId = (string) auth()->id();
        $accountProfile = app(AccountDirectory::class)->profile($accountId);

        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Claim membership for this :subject', ['subject' => strtolower($this->context['subject_label'])]))
                    ->description(__('Tell us how you are connected to this record so moderators can verify your claim.'))
                    ->schema([
                        Select::make('applied_role')
                            ->label(__('Requested role'))
                            ->options($applicationRoleOptions)
                            ->rule(Rule::in(array_keys($applicationRoleOptions)))
                            ->required()
                            ->afterStateUpdatedJs(
                                $subjectType !== MembershipApplicationSubjectType::Institution
                                    ? <<<'JS'
                                        if ($state === 'owner') {
                                            $set('relationship', 'self')
                                        }
                                    JS
                                    : null
                            ),
                        Select::make('relationship')
                            ->label(__('Your relationship with :subject', ['subject' => $this->context['subject_label']]))
                            ->options($relationshipOptions)
                            ->rule(Rule::in(array_keys($relationshipOptions)))
                            ->required()
                            ->live()
                            ->native(false)
                            ->default($subjectType === MembershipApplicationSubjectType::Institution ? null : 'self')
                            ->disabled(fn (Get $get): bool => $subjectType !== MembershipApplicationSubjectType::Institution && $get('applied_role') === MemberRole::Owner->value)
                            ->extraInputAttributes(
                                $subjectType !== MembershipApplicationSubjectType::Institution
                                    ? ['x-bind:disabled' => "\$get('applied_role') === 'owner'"]
                                    : []
                            ),
                        TextInput::make('relationship_detail')
                            ->label(__('Specify your relationship'))
                            ->placeholder(__('cth. Menantu, jiran, bekas pelajar'))
                            ->maxLength(120)
                            ->visible(fn (Get $get): bool => MembershipApplicationFormRules::acceptsRelationshipDetail((string) $get('relationship')))
                            ->required(fn (Get $get): bool => $get('relationship') === MembershipApplicationFormRules::OTHER_RELATIONSHIP),
                        PhoneInput::make('phone')
                            ->label(__('Phone Number'))
                            ->initialCountry('MY')
                            ->displayNumberFormat(PhoneInputNumberType::INTERNATIONAL)
                            ->inputNumberFormat(PhoneInputNumberType::E164)
                            ->required()
                            ->visible(blank($accountProfile?->phone))
                            ->rule(Rule::unique('users', 'phone')->ignore($accountId))
                            ->helperText(__('We need a contact number to verify your claim. This is saved to your profile, not the application.'))
                            ->columnSpanFull(),
                        Textarea::make('notes')
                            ->label(__('Catatan'))
                            ->placeholder(__('Tulis apa-apa maklumat tambahan yang berkaitan dengan permohonan anda.'))
                            ->helperText(__('Catatan ini akan dibaca oleh penyemak bersama bukti yang anda hantar.'))
                            ->rows(4)
                            ->maxLength(2000)
                            ->columnSpanFull(),
                        FileUpload::make('evidence')
                            ->label(__('Evidence Files'))
                            ->multiple()
                            ->reorderable()
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                            ->maxSize((int) ceil(((int) config('media-library.max_file_size')) / 1024))
                            ->maxFiles(8)
                            ->minFiles(1)
                            ->required()
                            ->storeFiles(false)
                            ->openable()
                            ->downloadable()
                            ->helperText(__('Upload screenshots, letters, profile pages, or PDFs that support your claim.'))
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public function submit(
        MembershipApplications $membershipApplications,
        AccountAdministration $accountAdministration,
        StagedUploadFactory $stagedUploadFactory,
    ): void {
        $user = auth()->user();
        abort_unless($user instanceof FeedbackActor, 403);

        if (! $user->canSubmitDirectoryFeedback()) {
            abort(403, $user->directoryFeedbackBanMessage());
        }

        $accountId = $user->actorId();
        $accountProfile = app(AccountDirectory::class)->profile($accountId);
        $state = $this->applicationForm()->getState();
        $subjectType = MembershipApplicationSubjectType::tryFrom($this->subjectType);
        abort_unless($subjectType instanceof MembershipApplicationSubjectType, 404);

        $rawAppliedRole = $state['applied_role'] ?? null;
        $appliedRole = $rawAppliedRole instanceof MemberRole
            ? $rawAppliedRole->value
            : (string) $rawAppliedRole;
        if (! array_key_exists($appliedRole, MembershipApplicationFormRules::applicationRoleOptions())) {
            $this->addError('data.applied_role', __('Please select a valid role.'));

            return;
        }

        $memberRole = MemberRole::from($appliedRole);

        $relationship = (string) ($state['relationship'] ?? ($subjectType === MembershipApplicationSubjectType::Institution ? '' : 'self'));
        if ($subjectType !== MembershipApplicationSubjectType::Institution && $memberRole === MemberRole::Owner) {
            $relationship = 'self';
        }

        $relationshipOptions = MembershipApplicationFormRules::relationshipOptions(
            $subjectType->membershipSubjectType(),
            $this->subjectInstitutionType(),
        );

        if (! array_key_exists($relationship, $relationshipOptions)) {
            $this->addError('data.relationship', __('Please select a valid relationship.'));

            return;
        }

        $relationshipDetail = MembershipApplicationFormRules::acceptsRelationshipDetail($relationship)
            ? trim((string) ($state['relationship_detail'] ?? ''))
            : '';

        if ($relationship === MembershipApplicationFormRules::OTHER_RELATIONSHIP && $relationshipDetail === '') {
            $this->addError('data.relationship_detail', __('Please specify your relationship.'));

            return;
        }

        $notes = trim((string) ($state['notes'] ?? ''));
        $phone = is_scalar($state['phone'] ?? null)
            ? trim((string) $state['phone'])
            : null;

        $relationshipLabel = $relationshipOptions[$relationship];

        if ($relationshipDetail !== '') {
            $relationshipLabel = sprintf('%s (%s)', $relationshipLabel, $relationshipDetail);
        }

        $justification = sprintf(
            'Applying as %s. Relationship: %s.',
            __($memberRole->label()),
            $relationshipLabel,
        );

        $meta = [
            'applied_role' => $appliedRole,
            'relationship' => $relationship,
            'relationship_detail' => $relationshipDetail !== '' ? $relationshipDetail : null,
            'notes' => $notes !== '' ? $notes : null,
        ];

        try {
            $membershipApplications->submit(new SubmitMembershipApplicationInput(
                subjectType: $subjectType->membershipSubjectType(),
                subjectId: $this->subjectId,
                accountId: $accountId,
                justification: $justification,
                meta: $meta,
                evidenceFiles: $this->stageEvidenceFiles($state['evidence'] ?? [], $stagedUploadFactory),
            ));
        } catch (RuntimeException $exception) {
            match ($exception->getMessage()) {
                'membership_application_already_member' => $this->addError('data.applied_role', __('You are already a member of this record.')),
                'membership_application_duplicate_pending' => $this->addError('data.applied_role', __('You already have a pending claim for this record.')),
                'membership_application_pending_invitation' => $this->addError('data.applied_role', __('You already have a pending invitation for this record. Please accept that invitation instead.')),
                default => throw $exception,
            };

            return;
        }

        if (filled($phone) && blank($accountProfile?->phone)) {
            $accountAdministration->update($accountId, ['phone' => $phone]);
        }

        $this->successToast(__('Membership claim submitted for review.'));

        $this->redirect(route('membership-applications.index'), navigate: true);
    }

    public function rendering(object $view): void
    {
        if (method_exists($view, 'title')) {
            $view->title(__('Claim Membership').' - '.config('app.name'));
        }
    }

    protected function applicationForm(): Schema
    {
        return $this->getForm('form') ?? throw new RuntimeException('Membership application form is not available.');
    }

    private function subjectInstitutionType(): ?InstitutionType
    {
        return $this->subjectType === MembershipApplicationSubjectType::Institution->value && $this->institutionTypeValue !== null
            ? InstitutionType::tryFrom($this->institutionTypeValue)
            : null;
    }

    private function canonicalSubjectId(): string
    {
        return $this->subjectType === MembershipApplicationSubjectType::Person->value
            ? (string) ($this->subjectSlug ?? $this->subjectId)
            : $this->subjectId;
    }

    private function resolveSubject(
        MembershipApplicationSubjectType $subjectType,
        string $subjectId,
        DirectoryReader $directoryReader,
    ): DirectoryDetail {
        $directoryRecordType = DirectoryRecordType::tryFrom($subjectType->value);
        abort_unless($directoryRecordType instanceof DirectoryRecordType, 404);

        return $directoryReader->find($directoryRecordType, $subjectId)
            ?? abort(404);
    }

    /**
     * @return array{subject_label: string, subject_title: string, redirect_url: string, admin_url: string, profile_image_url: string|null}
     */
    private function resolveSubjectPresentation(MembershipApplicationSubjectType $subjectType, DirectoryDetail $subject): array
    {
        return [
            'subject_label' => $subjectType->membershipSubjectType()->label(),
            'subject_title' => $subject->title,
            'redirect_url' => $subject->publicUrl ?? '',
            'admin_url' => '',
            'profile_image_url' => $subjectType === MembershipApplicationSubjectType::Person
                ? $subject->imageUrl
                : null,
        ];
    }

    private function shouldRedirectToCanonicalSubjectUrl(MembershipApplicationSubjectType $subjectType, string $subjectId): bool
    {
        $routeSubjectType = request()->route('subjectType');

        if (! is_string($routeSubjectType) || $routeSubjectType === '') {
            return false;
        }

        return $subjectType->publicRouteSegment() !== $routeSubjectType
            || $this->canonicalSubjectId() !== $subjectId;
    }

    /**
     * @return list<\App\Shared\Media\StagedUpload>
     */
    private function stageEvidenceFiles(mixed $files, StagedUploadFactory $stagedUploadFactory): array
    {
        if (! is_array($files)) {
            throw ValidationException::withMessages([
                'data.evidence' => [__('Please upload valid evidence files.')],
            ]);
        }

        $uploads = [];

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                throw ValidationException::withMessages([
                    'data.evidence' => [__('Please upload valid evidence files.')],
                ]);
            }

            try {
                $uploads[] = $stagedUploadFactory->fromUploadedFile($file);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages([
                    'data.evidence' => [__('Please upload valid evidence files.')],
                ]);
            }
        }

        return $uploads;
    }

    public function render(): View
    {
        return view('membership::livewire.pages.membership-applications.create');
    }
}
