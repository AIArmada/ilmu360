<?php

namespace App\Livewire\Pages\Contributions;

use App\Actions\Contributions\ApplyDirectContributionUpdateAction;
use App\Actions\Contributions\ResolveContributionChangedPayloadAction;
use App\Actions\Contributions\ResolveContributionSubjectPresentationAction;
use App\Actions\Contributions\ResolveContributionSubmissionStateAction;
use App\Actions\Contributions\ResolveContributionUpdateContextAction;
use App\Actions\Contributions\ResolveLatestPendingContributionRequestAction;
use App\Actions\Contributions\SubmitContributionUpdateRequestAction;
use App\Enums\ContributionSubjectType;
use App\Forms\EventContributionFormSchema;
use App\Forms\InstitutionContributionFormSchema;
use App\Forms\ReferenceContributionFormSchema;
use App\Forms\SharedFormSchema;
use App\Forms\SpeakerContributionFormSchema;
use App\Livewire\Concerns\InteractsWithLocationPickerSelection;
use App\Livewire\Concerns\InteractsWithToasts;
use App\Models\ContributionRequest;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Speaker;
use App\Models\User;
use App\Support\Events\EventContributionUpdateStateMapper;
use App\Support\Location\PreferredCountryResolver;
use App\Support\Location\PublicCountryRegistry;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;
use RuntimeException;

#[Layout('layouts.app')]
class SuggestUpdate extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;
    use InteractsWithLocationPickerSelection;
    use InteractsWithToasts;
    use WithFileUploads;

    public Event|Institution|Reference|Speaker $entity;

    public string $subjectType;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var array<string, mixed> */
    public array $originalData = [];

    /** @var list<string> */
    public array $directEditMediaFields = [];

    /** @var array{subject_label: string, redirect_url: string} */
    public array $subjectPresentation = [
        'subject_label' => '',
        'redirect_url' => '',
    ];

    public function mount(
        string $subjectType,
        string $subjectId,
        ResolveContributionUpdateContextAction $resolveContributionUpdateContextAction,
        ResolveContributionSubjectPresentationAction $resolveContributionSubjectPresentationAction,
    ): void {
        $resolvedSubjectType = ContributionSubjectType::fromRouteSegment($subjectType);

        abort_unless($resolvedSubjectType instanceof ContributionSubjectType, 404);

        $this->subjectType = $resolvedSubjectType->value;

        $context = $resolveContributionUpdateContextAction->handle($this->subjectType, $subjectId);

        $this->entity = $context['entity'];
        $this->originalData = $this->comparableOriginalData($context['initial_state']);
        $this->subjectPresentation = $resolveContributionSubjectPresentationAction->handle($this->entity);

        $user = auth()->user();

        abort_unless($user instanceof User, 403);
        abort_unless($user->can('view', $this->entity), 403);

        $this->directEditMediaFields = $user->can('update', $this->entity)
            ? array_values(array_filter(
                $context['contract']['direct_edit_media_fields'] ?? [],
                static fn (string $field): bool => $field !== '',
            ))
            : [];

        if (! $user->canSubmitDirectoryFeedback()) {
            abort(403, $user->directoryFeedbackBanMessage());
        }

        if ($this->shouldRedirectToCanonicalSubjectUrl($resolvedSubjectType, $subjectId)) {
            $this->redirectRoute('contributions.suggest-update', [
                'subjectType' => $resolvedSubjectType->publicRouteSegment(),
                'subjectId' => $this->canonicalSubjectId(),
            ], navigate: true);

            return;
        }

        $formState = $this->originalData;

        if ($this->entity instanceof Event && ($fixedTimezone = $this->fixedEventTimezone()) !== null) {
            $formState['timezone'] = $fixedTimezone;
            $formState = $this->eventComparableState($formState);
        }

        $this->contributionForm()->fill($formState);
    }

    #[Computed]
    public function canDirectEdit(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('update', $this->entity);
    }

    #[Computed]
    public function latestPendingRequest(): ?ContributionRequest
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return null;
        }

        return app(ResolveLatestPendingContributionRequestAction::class)->handle($user, $this->entity);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->model($this->entity)
            ->statePath('data')
            ->components([
                ...$this->subjectSchema(),
                Textarea::make('proposer_note')
                    ->label(__('Explain the change'))
                    ->helperText(__('Optional: add context that helps maintainers review your update faster.'))
                    ->rows(4)
                    ->maxLength(2000)
                    ->hidden(fn (): bool => $this->canDirectEdit()),
            ]);
    }

    public function submit(
        ApplyDirectContributionUpdateAction $applyDirectContributionUpdateAction,
        ResolveContributionChangedPayloadAction $resolveContributionChangedPayloadAction,
        ResolveContributionSubmissionStateAction $resolveContributionSubmissionStateAction,
        SubmitContributionUpdateRequestAction $submitContributionUpdateRequestAction,
    ): void {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        if (! $user->canSubmitDirectoryFeedback()) {
            abort(403, $user->directoryFeedbackBanMessage());
        }

        $submissionState = $resolveContributionSubmissionStateAction->handle($this->contributionForm()->getState());
        $state = $this->normalizeSubmissionState($submissionState['state']);
        $changes = $resolveContributionChangedPayloadAction->handle($state, $this->originalData);
        $hasDirectEditMediaChange = $this->canDirectEdit() && $this->hasDirectEditMediaChange();

        if ($changes === [] && ! $hasDirectEditMediaChange) {
            $this->addError('data', __('Make at least one change before continuing.'));

            return;
        }

        if ($this->canDirectEdit()) {
            if ($changes !== []) {
                $applyDirectContributionUpdateAction->handle($this->entity, $changes);
            }

            if ($hasDirectEditMediaChange) {
                $this->saveDirectEditMediaChanges();
            }

            $this->redirect($this->subjectPresentation['redirect_url'], navigate: true);

            return;
        }

        $submitContributionUpdateRequestAction->handle(
            $this->entity,
            $user,
            $changes,
            $submissionState['proposer_note'],
        );

        $this->redirect(route('contributions.index'), navigate: true);
    }

    public function rendering(object $view): void
    {
        if (method_exists($view, 'title')) {
            $view->title($this->pageHeading().' - '.config('app.name'));
        }
    }

    /**
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    private function subjectSchema(): array
    {
        return match (true) {
            $this->entity instanceof Institution => $this->institutionSubjectSchema(),
            $this->entity instanceof Speaker => $this->speakerSubjectSchema(),
            $this->entity instanceof Reference => ReferenceContributionFormSchema::components(includeMedia: false),
            default => $this->eventSubjectSchema(),
        };
    }

    /**
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    private function eventSubjectSchema(): array
    {
        $components = EventContributionFormSchema::components($this->fixedEventTimezone());

        if ($this->shouldShowDirectEditMediaSection()) {
            $components[] = $this->eventDirectEditMediaSection();
        }

        return $components;
    }

    /**
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    private function speakerSubjectSchema(): array
    {
        $components = SpeakerContributionFormSchema::components(
            includeMedia: false,
            addressStatePath: 'address',
            regionOnlyAddress: true,
        );

        if ($this->shouldShowDirectEditMediaSection()) {
            array_splice($components, 3, 0, [$this->speakerDirectEditMediaSection()]);
        }

        return $components;
    }

    private function fixedEventTimezone(): ?string
    {
        $countryId = app(PreferredCountryResolver::class)->resolveId();

        return app(PublicCountryRegistry::class)->singleTimezoneForCountryId($countryId);
    }

    /**
     * @param  array<string, mixed>  $initialState
     * @return array<string, mixed>
     */
    private function comparableOriginalData(array $initialState): array
    {
        if ($this->entity instanceof Event) {
            return $this->eventComparableState($initialState);
        }

        if (! $this->entity instanceof Speaker) {
            return $initialState;
        }

        $speakerAddress = is_array($initialState['address'] ?? null)
            ? $initialState['address']
            : [];

        $initialState['address'] = [
            'country_id' => SharedFormSchema::normalizeLocationId($speakerAddress['country_id'] ?? null) ?? SharedFormSchema::preferredPublicCountryId(),
            'state_id' => SharedFormSchema::normalizeLocationId($speakerAddress['state_id'] ?? null),
        ];

        if (($initialState['bio'] ?? null) === null) {
            $initialState['bio'] = [
                'type' => 'doc',
                'content' => [[
                    'type' => 'paragraph',
                    'content' => [],
                ]],
            ];
        }

        if (($districtId = SharedFormSchema::normalizeLocationId($speakerAddress['district_id'] ?? null)) !== null) {
            $initialState['address']['district_id'] = $districtId;
        }

        if (($subdistrictId = SharedFormSchema::normalizeLocationId($speakerAddress['subdistrict_id'] ?? null)) !== null) {
            $initialState['address']['subdistrict_id'] = $subdistrictId;
        }

        $initialState['qualifications'] = array_map(
            static function (mixed $qualification): mixed {
                if (! is_array($qualification)) {
                    return $qualification;
                }

                if (is_numeric($qualification['year'] ?? null)) {
                    $qualification['year'] = (int) $qualification['year'];
                }

                return $qualification;
            },
            is_array($initialState['qualifications'] ?? null) ? $initialState['qualifications'] : [],
        );

        $initialState['contacts'] = array_map(
            static function (mixed $contact): mixed {
                if (! is_array($contact)) {
                    return $contact;
                }

                return SharedFormSchema::normalizeContactRowsForComparison($contact);
            },
            is_array($initialState['contacts'] ?? null) ? $initialState['contacts'] : [],
        );

        return $initialState;
    }

    private function pageHeading(): string
    {
        return $this->canDirectEdit()
            ? __('Update :subject', ['subject' => $this->subjectPresentation['subject_label']])
            : __('Suggest an Update for :subject', ['subject' => $this->subjectPresentation['subject_label']]);
    }

    protected function contributionForm(): Schema
    {
        return $this->getForm('form') ?? throw new RuntimeException('Contribution update form is not available.');
    }

    private function canonicalSubjectId(): string
    {
        return match (true) {
            $this->entity instanceof Institution => $this->entity->slug,
            $this->entity instanceof Speaker => $this->entity->slug,
            $this->entity instanceof Reference => $this->entity->slug,
            default => $this->entity->slug,
        };
    }

    private function shouldRedirectToCanonicalSubjectUrl(ContributionSubjectType $subjectType, string $subjectId): bool
    {
        $routeSubjectType = request()->route('subjectType');

        if (! is_string($routeSubjectType) || $routeSubjectType === '') {
            return false;
        }

        return $subjectType->publicRouteSegment() !== $routeSubjectType
            || $this->canonicalSubjectId() !== $subjectId;
    }

    /**
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    private function institutionSubjectSchema(): array
    {
        return InstitutionContributionFormSchema::directEditComponents(
            addressStatePath: 'address',
            includeLocationPicker: true,
            mediaFields: $this->directEditMediaFields,
        );
    }

    private function shouldShowDirectEditMediaSection(): bool
    {
        return $this->canDirectEdit() && $this->directEditMediaFields !== [];
    }

    private function speakerDirectEditMediaSection(): Section
    {
        $components = [];

        if (in_array('avatar', $this->directEditMediaFields, true)) {
            $components[] = SpatieMediaLibraryFileUpload::make('avatar')
                ->label(__('Avatar'))
                ->collection('avatar')
                ->image()
                ->imageEditor()
                ->circleCropper()
                ->avatar()
                ->conversion('thumb')
                ->deletable(false)
                ->helperText(__('Recommended: a clear square image, at least 400x400px.'));
        }

        if (in_array('cover', $this->directEditMediaFields, true)) {
            $components[] = SpatieMediaLibraryFileUpload::make('cover')
                ->label(__('Cover Image'))
                ->collection('cover')
                ->image()
                ->imageEditor()
                ->imageAspectRatio('4:5')
                ->automaticallyOpenImageEditorForAspectRatio()
                ->imageEditorAspectRatioOptions(['4:5'])
                ->automaticallyCropImagesToAspectRatio()
                ->responsiveImages()
                ->conversion('banner')
                ->deletable(false)
                ->helperText(__('Cover image for speaker profile'));
        }

        if (in_array('gallery', $this->directEditMediaFields, true)) {
            $components[] = SpatieMediaLibraryFileUpload::make('gallery')
                ->label(__('Gallery'))
                ->collection('gallery')
                ->multiple()
                ->reorderable()
                ->image()
                ->responsiveImages()
                ->conversion('gallery_thumb')
                ->helperText(__('Additional images'));
        }

        return Section::make(__('Profile Photo & Media'))
            ->description(__('Upload a clear square profile photo first. Cover and gallery images are optional.'))
            ->schema($components)
            ->columns(['default' => 1, 'sm' => 2]);
    }

    private function eventDirectEditMediaSection(): Section
    {
        $components = [];

        if (in_array('cover', $this->directEditMediaFields, true)) {
            $components[] = SpatieMediaLibraryFileUpload::make('cover')
                ->label(__('Gambar Cover Majlis'))
                ->collection('cover')
                ->image()
                ->imageEditor()
                ->imageAspectRatio('16:9')
                ->automaticallyOpenImageEditorForAspectRatio()
                ->imageEditorAspectRatioOptions(['16:9', null])
                ->automaticallyCropImagesToAspectRatio()
                ->rules(['dimensions:ratio=16/9'])
                ->conversion('thumb')
                ->responsiveImages()
                ->deletable(false)
                ->helperText(__('Untuk paparan laman web dan aplikasi. Wajib 16:9, tanpa maklumat yang terlalu padat.'));
        }

        if (in_array('poster', $this->directEditMediaFields, true)) {
            $components[] = SpatieMediaLibraryFileUpload::make('poster')
                ->label(__('Poster Hebahan'))
                ->collection('poster')
                ->image()
                ->imageEditor()
                ->imageAspectRatio('4:5')
                ->automaticallyOpenImageEditorForAspectRatio()
                ->imageEditorAspectRatioOptions(['4:5', null])
                ->automaticallyCropImagesToAspectRatio()
                ->rules(['dimensions:ratio=4/5'])
                ->conversion('thumb')
                ->responsiveImages()
                ->deletable(false)
                ->helperText(__('Untuk hebahan WhatsApp, Instagram, Facebook, dan saluran luar. Wajib portrait 4:5 dan boleh mengandungi maklumat penuh.'));
        }

        if (in_array('gallery', $this->directEditMediaFields, true)) {
            $components[] = SpatieMediaLibraryFileUpload::make('gallery')
                ->label(__('Galeri'))
                ->collection('gallery')
                ->multiple()
                ->reorderable()
                ->maxFiles(10)
                ->image()
                ->imageEditor()
                ->conversion('thumb')
                ->responsiveImages()
                ->helperText(__('Gambar tambahan untuk galeri majlis.'));
        }

        return Section::make(__('Media'))
            ->schema($components)
            ->columns(['default' => 1, 'sm' => 2]);
    }

    private function hasDirectEditMediaChange(): bool
    {
        return array_any($this->directEditMediaFields, fn ($field) => $this->directEditMediaFieldChanged($field));
    }

    private function directEditMediaFieldChanged(string $field): bool
    {
        if (! $this->entity instanceof Event && ! $this->entity instanceof Institution && ! $this->entity instanceof Speaker) {
            return false;
        }

        $mediaField = $this->directEditMediaField($field);

        if (! $mediaField instanceof SpatieMediaLibraryFileUpload) {
            return false;
        }

        $currentState = array_keys($this->currentDirectEditMediaState($field));
        $submittedState = array_keys(is_array($mediaField->getRawState()) ? $mediaField->getRawState() : []);

        if ($field !== 'gallery') {
            sort($currentState);
            sort($submittedState);
        }

        return $currentState !== $submittedState;
    }

    private function directEditMediaField(string $field): ?SpatieMediaLibraryFileUpload
    {
        $field = $this->contributionForm()->getFlatFields(withHidden: true)[$field] ?? null;

        return $field instanceof SpatieMediaLibraryFileUpload ? $field : null;
    }

    /**
     * @return array<string, string>
     */
    private function currentDirectEditMediaState(string $field): array
    {
        if (! $this->entity instanceof Event && ! $this->entity instanceof Institution && ! $this->entity instanceof Speaker) {
            return [];
        }

        return $this->entity
            ->load('media')
            ->getMedia($field)
            ->mapWithKeys(static fn ($media): array => [$media->uuid => $media->uuid])
            ->all();
    }

    private function saveDirectEditMediaChanges(): void
    {
        $this->contributionForm()->model($this->entity)->saveRelationships();
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function normalizeSubmissionState(array $state): array
    {
        if (! $this->entity instanceof Event) {
            return $state;
        }

        return EventContributionUpdateStateMapper::toPersistenceState($state);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function eventComparableState(array $state): array
    {
        return EventContributionUpdateStateMapper::toHelperState($state);
    }
}
