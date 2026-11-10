<?php

namespace App\Livewire\Pages\Contributions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Actions\Contributions\Queries\FindEntityPendingContributionRequestAction;
use App\Actions\Contributions\Queries\ResolveContributionUpdateContextAction;
use App\Actions\Contributions\Submission\ApplyDirectContributionUpdateAction;
use App\Actions\Contributions\Submission\EnsureNoPendingContributionRequestAction;
use App\Actions\Contributions\Submission\EnsureUniqueContributionCreateAction;
use App\Actions\Contributions\Submission\NormalizeContributionSubmissionAction;
use App\Actions\Contributions\Submission\ResolveContributionChangedPayloadAction;
use App\Actions\Contributions\Submission\SubmitContributionUpdateRequestAction;
use App\Actions\Institutions\MergeInstitutionMapsSelectionIntoFormAction;
use App\Actions\Institutions\ResolveInstitutionMapsPlaceSelectionAction;
use App\Enums\ContributionSubjectType;
use App\Forms\EventContributionFormSchema;
use App\Forms\InstitutionContributionFormSchema;
use App\Forms\PersonContributionFormSchema;
use App\Forms\ReferenceContributionFormSchema;
use App\Forms\SharedFormSchema;
use App\Livewire\Concerns\InteractsWithLocationPickerSelection;
use App\Models\ContributionRequest;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\User;
use App\Platform\Location\FetchGooglePlaceDetailsAction;
use App\Platform\Location\FetchGooglePlacePhotoPreviewsAction;
use App\Platform\Location\GooglePlaceIdentity;
use App\Platform\Ui\InteractsWithToasts;
use App\Services\ContributionEntityMutationService;
use App\Shared\Signals\SignalRecorder;
use App\Support\Events\EventContributionUpdateStateMapper;
use App\Support\Submission\ContributionSubjectPresenter;
use Closure;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use League\Flysystem\UnableToCheckFileExistence;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

#[Layout('layouts.app')]
class SuggestUpdate extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;
    use InteractsWithLocationPickerSelection;
    use InteractsWithToasts;
    use WithFileUploads;

    public Event|Institution|Reference|Person $entity;

    public string $subjectType;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var array<string, mixed> */
    public array $originalData = [];

    /** @var list<string> */
    public array $directEditMediaFields = [];

    /**
     * Media UUIDs present on the entity when the form was mounted, used to detect
     * newly uploaded media that must be staged for moderation.
     *
     * @var array<string, array<string, string>>
     */
    #[Locked]
    public array $originalMediaUuids = [];

    /**
     * Visitors cannot apply media directly. Their uploads are held on a separate
     * collection (not the displayed one) so the entity's existing media is never
     * mutated or replaced until a reviewer approves the contribution.
     */
    private const VISITOR_MEDIA_HOLDING = 'contribution_staging';

    private ?bool $directEditPermission = null;

    /** @var array{subject_label: string, redirect_url: string} */
    public array $subjectPresentation = [
        'subject_label' => '',
        'redirect_url' => '',
    ];

    #[Locked]
    public ?string $verifiedMapsSelectionToken = null;

    public function mount(
        string $subjectType,
        string $subjectId,
        ResolveContributionUpdateContextAction $resolveContributionUpdateContextAction,
        ContributionSubjectPresenter $contributionSubjectPresenter,
    ): void {
        // Directory contribution pages operate on globally visible records. Keep the
        // explicit global context active for the full Livewire render, including
        // deferred media-field hydration after mount returns.
        OwnerContext::setForRequest(null);

        OwnerContext::withOwner(null, function () use (
            $contributionSubjectPresenter,
            $resolveContributionUpdateContextAction,
            $subjectId,
            $subjectType,
        ): void {
            $resolvedSubjectType = ContributionSubjectType::fromRouteSegment($subjectType);

            abort_unless($resolvedSubjectType instanceof ContributionSubjectType, 404);

            $this->subjectType = $resolvedSubjectType->value;

            $context = $resolveContributionUpdateContextAction->handle($this->subjectType, $subjectId);

            $this->entity = $context['entity'];
            $this->originalData = $this->comparableOriginalData($context['initial_state']);
            $this->subjectPresentation = $contributionSubjectPresenter->present($this->entity);

            $user = auth()->user();

            abort_unless($user instanceof User, 403);
            abort_unless($user->can('view', $this->entity), 403);

            $canDirectEdit = $this->canDirectEdit();

            $this->directEditMediaFields = array_values(array_filter(
                $context['contract']['direct_edit_media_fields'] ?? [],
                static fn (string $field): bool => $field !== '',
            ));

            $this->originalMediaUuids = collect($this->directEditMediaFields)
                ->mapWithKeys(fn (string $field): array => [$field => $this->currentDirectEditMediaState($field)])
                ->all();

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

            $this->originalData = $formState;

            $this->contributionForm()->fill($formState);
        });
    }

    #[Computed]
    public function canDirectEdit(): bool
    {
        if ($this->directEditPermission !== null) {
            return $this->directEditPermission;
        }

        $user = auth()->user();

        return $this->directEditPermission = $user instanceof User && $user->can('update', $this->entity);
    }

    #[Computed]
    public function pendingUpdateRequest(): ?ContributionRequest
    {
        return app(FindEntityPendingContributionRequestAction::class)->handle($this->entity);
    }

    /**
     * @param  array<string, mixed>  $selection
     * @return array<string, mixed>
     */
    public function applyPlaceSelection(
        string $statePath,
        array $selection,
        ResolveInstitutionMapsPlaceSelectionAction $resolveInstitutionMapsPlaceSelectionAction,
        MergeInstitutionMapsSelectionIntoFormAction $mergeInstitutionMapsSelectionIntoFormAction,
    ): array {
        if (! $this->entity instanceof Institution || $statePath !== 'data.address') {
            throw ValidationException::withMessages([
                'data.address.google_place_id' => __('Select an institution from the Google Maps search.'),
            ]);
        }

        $placeId = $selection['placeId'] ?? null;

        if (! is_string($placeId) || trim($placeId) === '') {
            throw ValidationException::withMessages([
                'data.address.google_place_id' => __('Select an institution from the Google Maps search.'),
            ]);
        }

        // Seed untouched tracking with the stored values so a reselect fills
        // name/type the contributor has not modified, mirroring blank-fill
        // on the create form. User-edited values are preserved instead.
        $previousSelection = $this->verifiedMapsSelection() ?? [
            'autofilled_name' => data_get($this->originalData, 'name'),
            'autofilled_type' => data_get($this->originalData, 'type'),
        ];
        $selectionData = $resolveInstitutionMapsPlaceSelectionAction->handle($placeId, $statePath.'.google_place_id');
        $merged = $mergeInstitutionMapsSelectionIntoFormAction->handle(
            is_array($this->data) ? $this->data : [],
            $selectionData,
            $previousSelection,
        );
        $this->data = $merged['state'];
        $this->resetValidation('data.address.google_place_id');

        $token = Str::random(48);
        $previousToken = $this->verifiedMapsSelectionToken;
        $this->verifiedMapsSelectionToken = $token;
        Cache::put($this->verifiedMapsSelectionCacheKey($token), [
            ...$selectionData,
            'address' => Arr::except($selectionData['address'], ['cascade_reset_guard']),
            'autofilled_contacts' => $merged['autofilled_contacts'],
            'autofilled_social_profiles' => $merged['autofilled_social_profiles'],
            'autofilled_name' => $merged['autofilled_name'],
            'autofilled_type' => $merged['autofilled_type'],
        ], now()->addMinutes(30));

        if (is_string($previousToken) && $previousToken !== '') {
            Cache::forget($this->verifiedMapsSelectionCacheKey($previousToken));
        }

        $user = auth()->user();

        if ($user instanceof User) {
            app(SignalRecorder::class)->record(
                eventName: 'contribution.institution_maps_place_selected',
                eventCategory: 'contribution',
                actorId: (string) $user->getKey(),
                properties: [
                    'has_website' => $selectionData['website_url'] !== null,
                    'social_profile_count' => count($selectionData['autofilled_social_profiles']),
                ],
                request: request(),
            );
        }

        return [
            ...Arr::only($selectionData['address'], [
                'google_place_id',
                'google_display_name',
                'formatted_address',
                'latitude',
                'longitude',
            ]),
        ];
    }

    /**
     * @return list<array{
     *     preview_url: string,
     *     width: int|null,
     *     height: int|null,
     *     attributions: list<array{display_name: string|null, uri: string|null}>,
     *     google_maps_uri: string|null
     * }>
     */
    public function loadPlacePhotoPreviews(
        string $placeId,
        FetchGooglePlaceDetailsAction $fetchGooglePlaceDetailsAction,
        FetchGooglePlacePhotoPreviewsAction $fetchGooglePlacePhotoPreviewsAction,
    ): array {
        $verifiedSelection = $this->verifiedMapsSelection();

        if (! is_array($verifiedSelection)
            || ! is_string($verifiedSelection['place_id'] ?? null)
            || ! hash_equals($verifiedSelection['place_id'], $placeId)) {
            return [];
        }

        try {
            $place = $fetchGooglePlaceDetailsAction->handle($placeId, 'data.address.google_place_id');

            return $fetchGooglePlacePhotoPreviewsAction->handle(
                $placeId,
                is_array($place['photos'] ?? null) ? $place['photos'] : [],
            );
        } catch (\Throwable) {
            return [];
        }
    }

    public function form(Schema $schema): Schema
    {
        return OwnerContext::withOwner(null, fn (): Schema => $schema
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
            ]));
    }

    public function submit(
        ApplyDirectContributionUpdateAction $applyDirectContributionUpdateAction,
        ResolveContributionChangedPayloadAction $resolveContributionChangedPayloadAction,
        NormalizeContributionSubmissionAction $normalizeContributionSubmissionAction,
        SubmitContributionUpdateRequestAction $submitContributionUpdateRequestAction,
        EnsureNoPendingContributionRequestAction $ensureNoPendingContributionRequestAction,
    ): void {
        OwnerContext::withOwner(null, function () use (
            $applyDirectContributionUpdateAction,
            $resolveContributionChangedPayloadAction,
            $normalizeContributionSubmissionAction,
            $submitContributionUpdateRequestAction,
            $ensureNoPendingContributionRequestAction,
        ): void {
            $user = auth()->user();

            abort_unless($user instanceof User, 403);

            if (! $user->canSubmitDirectoryFeedback()) {
                abort(403, $user->directoryFeedbackBanMessage());
            }

            $ensureNoPendingContributionRequestAction->handle($this->entity);

            $formState = $this->contributionForm()->getState();
            $donationQrUploads = SharedFormSchema::collectDonationQrUploads($formState['donation_channels'] ?? null);
            $rawState = SharedFormSchema::withoutDonationQrUploads($formState);
            $submittedDonationRows = is_array($rawState['donation_channels'] ?? null) ? $rawState['donation_channels'] : [];
            $formState = $this->mergeVerifiedInstitutionMapsSelection($rawState);
            $submissionState = $normalizeContributionSubmissionAction->handle($formState);
            $state = $this->normalizeSubmissionState($submissionState['state']);
            $changes = $resolveContributionChangedPayloadAction->handle(
                $state,
                $this->originalData + EventContributionUpdateStateMapper::provenanceOriginals($this->entity),
            );
            $hasDirectEditMediaChange = $this->hasDirectEditMediaChange();
            $hasNewMedia = $this->hasNewMedia();

            if ($changes === [] && ! $hasNewMedia && ! $hasDirectEditMediaChange && $donationQrUploads === []) {
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

                if ($donationQrUploads !== []) {
                    app(ContributionEntityMutationService::class)->attachDonationQrUploads(
                        $this->entity,
                        $submittedDonationRows,
                        $donationQrUploads,
                    );
                }

                $this->clearVerifiedMapsSelection();
                $this->redirect($this->subjectPresentation['redirect_url'], navigate: true);

                return;
            }

            $request = $submitContributionUpdateRequestAction->handle(
                $this->entity,
                $user,
                $changes,
                $submissionState['proposer_note'],
            );

            if ($hasNewMedia) {
                // Non-privileged contributors cannot apply media directly. Stage the
                // uploaded media on the pending request so it is moderated like the
                // rest of the proposed changes.
                $this->stageDirectEditMediaChanges($request);
            }

            if ($donationQrUploads !== []) {
                $this->stageDonationQrUploads($request, $submittedDonationRows, $donationQrUploads);
            }

            $this->clearVerifiedMapsSelection();
            $this->redirect(route('contributions.index'), navigate: true);
        });
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
            $this->entity instanceof Person => $this->personSubjectSchema(),
            $this->entity instanceof Reference => $this->referenceSubjectSchema(),
            default => $this->eventSubjectSchema(),
        };
    }

    /**
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    private function eventSubjectSchema(): array
    {
        $components = EventContributionFormSchema::components($this->fixedEventTimezone(), $this->canDirectEdit(), $this->entity instanceof Event ? $this->entity : null);

        if ($this->shouldShowDirectEditMediaSection()) {
            $components[] = $this->eventDirectEditMediaSection();
        }

        return $components;
    }

    /**
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    private function personSubjectSchema(): array
    {
        $sections = PersonContributionFormSchema::components(
            includeMedia: false,
            addressStatePath: 'address',
            regionOnlyAddress: true,
            includeAlternativeNames: true,
            useTitleMultiSelect: true,
            useInstitutionRepeater: true,
            splitProfileSections: true,
            includeWazeUrlField: false,
        );

        // PersonContributionFormSchema::components(includeMedia: false) returns:
        // 0: Core profile, 1: Optional profile, 2: Affiliated Institutions,
        // 3: Address, 4: Contact, 5: Social Media

        $mediaSchema = $this->shouldShowDirectEditMediaSection()
            ? [$this->personDirectEditMediaSection()]
            : [];

        return [
            Tabs::make('PersonUpdateTabs')
                ->id('person-update-tabs')
                ->columnSpanFull()
                ->tabs([
                    Tab::make(__('Maklumat Utama'))
                        ->icon(Heroicon::User)
                        ->schema([$sections[0]]),
                    Tab::make(__('Maklumat Tambahan'))
                        ->icon(Heroicon::AcademicCap)
                        ->schema([$sections[1]]),
                    Tab::make(__('Afiliasi'))
                        ->icon(Heroicon::BuildingOffice)
                        ->schema([$sections[2]]),
                    Tab::make(__('Lokasi'))
                        ->icon(Heroicon::MapPin)
                        ->schema([$sections[3]]),
                    Tab::make(__('Hubungan'))
                        ->icon(Heroicon::ChatBubbleLeftRight)
                        ->schema([$sections[4], $sections[5]]),
                    ...($mediaSchema !== [] ? [
                        Tab::make(__('Media'))
                            ->icon(Heroicon::Photo)
                            ->schema($mediaSchema),
                    ] : []),
                ]),
        ];
    }

    private function fixedEventTimezone(): ?string
    {
        $timezone = $this->entity instanceof Event ? $this->entity->timezone : null;

        if (is_string($timezone) && $timezone !== '') {
            return $timezone;
        }

        return config('app.default_user_timezone', config('app.timezone', 'UTC'));
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

        if (! $this->entity instanceof Person) {
            return $initialState;
        }

        $personAddress = is_array($initialState['address'] ?? null)
            ? $initialState['address']
            : [];

        $initialState['address'] = SharedFormSchema::expandStoredAreasForForm([
            'country_id' => SharedFormSchema::normalizeLocationId($personAddress['country_id'] ?? null),
            'area_assignments' => (array) ($personAddress['area_assignments'] ?? []),
        ]);

        if (($initialState['bio'] ?? null) === null) {
            $initialState['bio'] = [
                'type' => 'doc',
                'content' => [[
                    'type' => 'paragraph',
                    'content' => [],
                ]],
            ];
        }

        $initialState['contactMethods'] = array_map(
            static function (mixed $contact): mixed {
                if (! is_array($contact)) {
                    return $contact;
                }

                return SharedFormSchema::normalizeContactRowsForComparison($contact);
            },
            is_array($initialState['contactMethods'] ?? null) ? $initialState['contactMethods'] : [],
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
            $this->entity instanceof Person => $this->entity->slug,
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
        $storedPlaceId = data_get($this->originalData, 'address.google_place_id');

        return InstitutionContributionFormSchema::directEditComponents(
            addressStatePath: 'address',
            includeLocationPicker: true,
            mediaFields: $this->directEditMediaFields,
            includeWazeUrlField: false,
            includeMapsReselection: true,
            mapsReselectionRequired: ! is_string($storedPlaceId) || trim($storedPlaceId) === '',
        );
    }

    /**
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    private function referenceSubjectSchema(): array
    {
        $components = ReferenceContributionFormSchema::components(includeMedia: false);

        if ($this->shouldShowDirectEditMediaSection()) {
            $components[] = $this->referenceDirectEditMediaSection();
        }

        return $components;
    }

    private function referenceDirectEditMediaSection(): Section
    {
        $components = [];

        $configureVisitorMedia = function (SpatieMediaLibraryFileUpload $component): SpatieMediaLibraryFileUpload {
            if (! $this->canDirectEdit()) {
                $component->saveRelationshipsUsing($this->visitorMediaRelationshipSaver())->saveUploadedFileUsing($this->visitorMediaFileSaver())->deletable(false);
            }

            return $component;
        };

        if (in_array('front_cover', $this->directEditMediaFields, true)) {
            $components[] = $configureVisitorMedia(SpatieMediaLibraryFileUpload::make('front_cover')
                ->label(__('Front Cover'))
                ->collection('front_cover')
                ->image()
                ->imageEditor()
                ->imageAspectRatio('3:4')
                ->automaticallyOpenImageEditorForAspectRatio()
                ->imageEditorAspectRatioOptions(['3:4'])
                ->automaticallyCropImagesToAspectRatio()
                ->responsiveImages()
                ->conversion('thumb')
                ->helperText(__('Front cover image of the reference, using a 3:4 ratio.')));
        }

        if (in_array('back_cover', $this->directEditMediaFields, true)) {
            $components[] = $configureVisitorMedia(SpatieMediaLibraryFileUpload::make('back_cover')
                ->label(__('Back Cover'))
                ->collection('back_cover')
                ->image()
                ->imageEditor()
                ->imageAspectRatio('3:4')
                ->automaticallyOpenImageEditorForAspectRatio()
                ->imageEditorAspectRatioOptions(['3:4'])
                ->automaticallyCropImagesToAspectRatio()
                ->responsiveImages()
                ->conversion('thumb')
                ->helperText(__('Back cover image of the reference, using a 3:4 ratio.')));
        }

        if (in_array('gallery', $this->directEditMediaFields, true)) {
            $components[] = $configureVisitorMedia(SpatieMediaLibraryFileUpload::make('gallery')
                ->label(__('Gallery'))
                ->collection('gallery')
                ->multiple()
                ->reorderable()
                ->image()
                ->responsiveImages()
                ->conversion('gallery_thumb')
                ->helperText(__('Additional images related to the reference.')));
        }

        return Section::make(__('Reference Imagery'))
            ->schema($components)
            ->columns(['default' => 1, 'sm' => 2]);
    }

    private function shouldShowDirectEditMediaSection(): bool
    {
        if ($this->directEditMediaFields === []) {
            return false;
        }

        return true;
    }

    private function personDirectEditMediaSection(): Section
    {
        $components = [];

        $configureVisitorMedia = function (SpatieMediaLibraryFileUpload $component): SpatieMediaLibraryFileUpload {
            if (! $this->canDirectEdit()) {
                $component->saveRelationshipsUsing($this->visitorMediaRelationshipSaver())->saveUploadedFileUsing($this->visitorMediaFileSaver())->deletable(false);
            }

            return $component;
        };

        if (in_array('avatar', $this->directEditMediaFields, true)) {
            $components[] = $configureVisitorMedia(SpatieMediaLibraryFileUpload::make('avatar')
                ->label(__('Profile avatar'))
                ->collection('avatar')
                ->image()
                ->imageEditor()
                ->circleCropper()
                ->avatar()
                ->conversion('thumb')
                ->helperText(__('Use a clear square profile image for the main avatar, at least 400x400px.')));
        }

        if (in_array('main', $this->directEditMediaFields, true)) {
            $components[] = $configureVisitorMedia(SpatieMediaLibraryFileUpload::make('main')
                ->label(__('Main Photo'))
                ->collection('main')
                ->image()
                ->imageEditor()
                ->imageAspectRatio('1:1')
                ->automaticallyOpenImageEditorForAspectRatio()
                ->imageEditorAspectRatioOptions(['1:1'])
                ->automaticallyCropImagesToAspectRatio()
                ->responsiveImages()
                ->conversion('thumb')
                ->helperText(__('Primary speaker portrait for directory cards, using a 1:1 ratio.')));
        }

        if (in_array('profile', $this->directEditMediaFields, true)) {
            $components[] = $configureVisitorMedia(SpatieMediaLibraryFileUpload::make('profile')
                ->label(__('Profile Photo'))
                ->collection('profile')
                ->image()
                ->imageEditor()
                ->imageAspectRatio('3:4')
                ->automaticallyOpenImageEditorForAspectRatio()
                ->imageEditorAspectRatioOptions(['3:4'])
                ->automaticallyCropImagesToAspectRatio()
                ->responsiveImages()
                ->conversion('profile_thumb')
                ->helperText(__('Vertical speaker portrait for the profile page, using a 3:4 ratio.')));
        }

        if (in_array('cover', $this->directEditMediaFields, true)) {
            $components[] = $configureVisitorMedia(SpatieMediaLibraryFileUpload::make('cover')
                ->label(__('Cover Image'))
                ->collection('cover')
                ->image()
                ->imageEditor()
                ->imageAspectRatio('16:9')
                ->automaticallyOpenImageEditorForAspectRatio()
                ->imageEditorAspectRatioOptions(['16:9', null])
                ->automaticallyCropImagesToAspectRatio()
                ->responsiveImages()
                ->conversion('banner')
                ->helperText(__('Wide cover image for the speaker profile, using a 16:9 ratio.')));
        }

        if (in_array('gallery', $this->directEditMediaFields, true)) {
            $components[] = $configureVisitorMedia(SpatieMediaLibraryFileUpload::make('gallery')
                ->label(__('Gallery'))
                ->collection('gallery')
                ->multiple()
                ->reorderable()
                ->image()
                ->responsiveImages()
                ->conversion('gallery_thumb')
                ->helperText(__('Additional photos related to the speaker.')));
        }

        return Section::make(__('Profile Photo & Media'))
            ->description(__('All media fields are optional. If available, add a clear square profile photo first; cover and gallery images are optional.'))
            ->schema($components)
            ->columns(['default' => 1, 'sm' => 2]);
    }

    private function eventDirectEditMediaSection(): Section
    {
        $components = [];

        $configureVisitorMedia = function (SpatieMediaLibraryFileUpload $component): SpatieMediaLibraryFileUpload {
            if (! $this->canDirectEdit()) {
                $component->saveRelationshipsUsing($this->visitorMediaRelationshipSaver())->saveUploadedFileUsing($this->visitorMediaFileSaver())->deletable(false);
            }

            return $component;
        };

        if (in_array('cover', $this->directEditMediaFields, true)) {
            $components[] = $configureVisitorMedia(SpatieMediaLibraryFileUpload::make('cover')
                ->label(__('Gambar Cover Majlis'))
                ->collection('cover')
                ->image()
                ->imageEditor()
                ->imageAspectRatio('16:9')
                ->automaticallyOpenImageEditorForAspectRatio()
                ->imageEditorAspectRatioOptions(['16:9', null])
                ->automaticallyCropImagesToAspectRatio()
                ->conversion('thumb')
                ->responsiveImages()
                ->deletable(false)
                ->helperText(__('Untuk paparan laman web dan aplikasi. Wajib 16:9, tanpa maklumat yang terlalu padat.')));
        }

        if (in_array('poster', $this->directEditMediaFields, true)) {
            $components[] = $configureVisitorMedia(SpatieMediaLibraryFileUpload::make('poster')
                ->label(__('Poster Hebahan'))
                ->collection('poster')
                ->image()
                ->imageEditor()
                ->imageAspectRatio('3:4')
                ->automaticallyOpenImageEditorForAspectRatio()
                ->imageEditorAspectRatioOptions(['3:4', null])
                ->automaticallyCropImagesToAspectRatio()
                ->rules(['dimensions:ratio=3/4'])
                ->conversion('poster_thumb')
                ->responsiveImages()
                ->deletable(false)
                ->helperText(__('Untuk hebahan WhatsApp, Instagram, Facebook, dan saluran luar. Wajib portrait 3:4 dan boleh mengandungi maklumat penuh.')));
        }

        if (in_array('gallery', $this->directEditMediaFields, true)) {
            $components[] = $configureVisitorMedia(SpatieMediaLibraryFileUpload::make('gallery')
                ->label(__('Galeri'))
                ->collection('gallery')
                ->multiple()
                ->reorderable()
                ->maxFiles(10)
                ->image()
                ->imageEditor()
                ->conversion('gallery_thumb')
                ->responsiveImages()
                ->helperText(__('Gambar tambahan untuk galeri majlis.')));
        }

        return Section::make(__('Media'))
            ->schema($components)
            ->columns(['default' => 1, 'sm' => 2]);
    }

    private function hasDirectEditMediaChange(): bool
    {
        return array_any($this->directEditMediaFields, fn ($field) => $this->directEditMediaFieldChanged($field));
    }

    /**
     * @return list<string>
     */
    private function newMediaUuids(string $field): array
    {
        $current = Media::query()
            ->where('model_type', $this->entity->getMorphClass())
            ->where('model_id', $this->entity->getKey())
            ->where('collection_name', $field)
            ->pluck('uuid')
            ->all();

        // Visitor uploads live on the holding collection, tagged with the target field,
        // so they are detected as "new" without ever touching the displayed collection.
        $staged = $this->entity
            ->getMedia(self::VISITOR_MEDIA_HOLDING)
            ->filter(static fn (Media $media): bool => ($media->custom_properties['contribution_field'] ?? null) === $field)
            ->pluck('uuid')
            ->all();

        $original = array_keys($this->originalMediaUuids[$field] ?? []);

        return array_values(array_unique([...array_diff($current, $original), ...$staged]));
    }

    private function hasNewMedia(): bool
    {
        foreach ($this->directEditMediaFields as $field) {
            if ($this->newMediaUuids($field) !== []) {
                return true;
            }
        }

        return false;
    }

    private function directEditMediaFieldChanged(string $field): bool
    {
        if (! $this->entity instanceof Event && ! $this->entity instanceof Institution && ! $this->entity instanceof Person) {
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
        return $this->entity
            ->loadMissing('media')
            ->getMedia($field)
            ->mapWithKeys(static fn ($media): array => [$media->uuid => $media->uuid])
            ->all();
    }

    private function saveDirectEditMediaChanges(): void
    {
        $mediaIdsBeforeSave = collect($this->directEditMediaFields)
            ->mapWithKeys(fn (string $field): array => [$field => $this->currentDirectEditMediaState($field)])
            ->all();

        $this->contributionForm()->model($this->entity)->saveRelationships();

        // Direct editors (owners/managers) commit media changes immediately, including
        // removals: any pre-existing entity media omitted from the submission is deleted.
        // Visitors stage changes for review, so their originals must never be deleted
        // here — only the newly uploaded media is staged (moved to pending_media), and
        // the originals remain on the entity until a reviewer approves the request.
        if (! $this->canDirectEdit()) {
            return;
        }

        foreach ($mediaIdsBeforeSave as $field => $mediaIds) {
            $mediaField = $this->directEditMediaField($field);
            if (! $mediaField instanceof SpatieMediaLibraryFileUpload) {
                continue;
            }

            $submittedIds = array_keys(is_array($mediaField->getRawState()) ? $mediaField->getRawState() : []);

            $this->entity->getMedia($field)
                ->filter(fn ($media): bool => isset($mediaIds[$media->uuid]) && ! in_array($media->uuid, $submittedIds, true))
                ->each->delete();
        }
    }

    /**
     * Move uploaded media from the entity onto the pending contribution request so
     * it is applied only after a reviewer approves the request.
     */
    private function stageDirectEditMediaChanges(ContributionRequest $request): void
    {
        foreach ($this->directEditMediaFields as $field) {
            $newUuids = $this->newMediaUuids($field);

            if ($newUuids === []) {
                continue;
            }

            $newMedia = Media::query()->whereIn('uuid', $newUuids)->orderBy('order_column')->get();

            foreach ($newMedia as $media) {
                $request->addMediaFromDisk($media->getPathRelativeToRoot(), $media->disk)
                    ->usingFileName($media->file_name)
                    ->withCustomProperties([
                        'contribution_field' => $field,
                        'is_single' => $field !== 'gallery',
                    ])
                    ->toMediaCollection('pending_media');

                $media->delete();
            }
        }
    }

    /**
     * Persist visitor donation QR uploads onto the pending request keyed by
     * channel identity, so approval can attach each QR to its channel.
     * Temporary uploads would not survive until review, so contents are read
     * immediately while the files still exist.
     *
     * @param  array<int|string, array<string, mixed>>  $submittedRows
     * @param  array<int|string, TemporaryUploadedFile>  $uploads
     */
    private function stageDonationQrUploads(ContributionRequest $request, array $submittedRows, array $uploads): void
    {
        $failed = [];

        foreach ($uploads as $index => $file) {
            $row = $submittedRows[$index] ?? null;
            $fingerprint = is_array($row) ? ContributionEntityMutationService::donationChannelFingerprint($row) : null;

            try {
                $contents = $file->exists() ? $file->get() : null;
            } catch (\Throwable) {
                $contents = null;
            }

            if (! is_string($contents) || $contents === '' || $fingerprint === null) {
                $failed["data.donation_channels.{$index}.qr_upload"] = __('The QR upload expired or is invalid. Please upload the QR image again.');

                continue;
            }

            $request->addMediaFromString($contents)
                ->usingFileName($file->getClientOriginalName() !== '' ? $file->getClientOriginalName() : 'qr.png')
                ->withCustomProperties([
                    'contribution_field' => 'donation_qr',
                    'donation_fingerprint' => $fingerprint,
                    'is_single' => true,
                ])
                ->toMediaCollection('pending_media');
        }

        if ($failed !== []) {
            throw ValidationException::withMessages($failed);
        }
    }

    /**
     * Visitor (non-direct-edit) uploads must never mutate the real entity's
     * existing media. The default saver deletes "abandoned" files and a single-file
     * collection replaces the previous media, either of which would destroy a
     * pre-existing avatar/cover the moment a visitor uploads a replacement — if the
     * request is later rejected the original is lost forever. This saver adds the
     * upload but skips deleteAbandonedFiles(), and the file saver below diverts the
     * upload to a holding collection so the displayed collection is untouched.
     */
    private function visitorMediaRelationshipSaver(): Closure
    {
        return static function (SpatieMediaLibraryFileUpload $component): void {
            $component->saveUploadedFiles();
        };
    }

    /**
     * Saves a visitor upload to the holding collection (tagged with the target
     * field) instead of the entity's displayed collection, so existing media is
     * never replaced or deleted during the (unapproved) contribution.
     */
    private function visitorMediaFileSaver(): Closure
    {
        return function (SpatieMediaLibraryFileUpload $component, TemporaryUploadedFile $file, ?Model $record): ?string {
            if ($record === null || ! method_exists($record, 'addMediaFromString')) {
                return $file;
            }

            try {
                if (! $file->exists()) {
                    return null;
                }
            } catch (UnableToCheckFileExistence) {
                return null;
            }

            /** @var Media $media */
            $media = $record->addMediaFromString($file->get())
                ->addCustomHeaders([['ContentType' => $file->getMimeType()], ...$component->getCustomHeaders()])
                ->usingFileName($component->getUploadedFileNameForStorage($file))
                ->usingName($component->getMediaName($file) ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))
                ->storingConversionsOnDisk($component->getConversionsDisk() ?? '')
                ->withCustomProperties(array_merge(
                    $component->getCustomProperties($file),
                    ['contribution_field' => $component->getCollection()],
                ))
                ->withManipulations($component->getManipulations())
                ->withResponsiveImagesIf($component->hasResponsiveImages())
                ->withProperties($component->getProperties())
                ->toMediaCollection(self::VISITOR_MEDIA_HOLDING, $component->getDiskName());

            return $media->getAttributeValue('uuid');
        };
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

        if (
            array_key_exists('event_date', $state)
            && $state['event_date'] !== ($this->originalData['event_date'] ?? null)
        ) {
            $state['end_date'] = null;
        } elseif (! array_key_exists('end_date', $state)) {
            $state['end_date'] = $this->originalData['end_date'] ?? null;
        }

        return EventContributionUpdateStateMapper::toPersistenceState($state, $this->entity);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function eventComparableState(array $state): array
    {
        return EventContributionUpdateStateMapper::toHelperState($state);
    }

    /**
     * @param  array<string, mixed>  $formState
     * @return array<string, mixed>
     */
    private function mergeVerifiedInstitutionMapsSelection(array $formState): array
    {
        if (! $this->entity instanceof Institution) {
            return $formState;
        }

        $submittedPlaceId = data_get($formState, 'address.google_place_id');
        $originalPlaceId = data_get($this->originalData, 'address.google_place_id');

        if (! is_string($submittedPlaceId) || trim($submittedPlaceId) === '') {
            throw ValidationException::withMessages([
                'data.address.google_place_id' => __('Select and verify the institution on Google Maps before submitting.'),
            ]);
        }

        if ($submittedPlaceId === $originalPlaceId) {
            return $formState;
        }

        $verifiedSelection = $this->verifiedMapsSelection();

        if (! is_array($verifiedSelection)
            || ! is_string($verifiedSelection['place_id'] ?? null)
            || ! hash_equals($verifiedSelection['place_id'], $submittedPlaceId)
        ) {
            throw ValidationException::withMessages([
                'data.address.google_place_id' => __('Select and verify the new location on Google Maps before submitting.'),
            ]);
        }

        $formAddress = is_array($formState['address'] ?? null) ? $formState['address'] : [];
        $verifiedAddress = is_array($verifiedSelection['address'] ?? null)
            ? $verifiedSelection['address']
            : [];
        $formState['address'] = array_merge($formAddress, Arr::only($verifiedAddress, [
            'google_maps_url',
            'google_place_id',
            'google_feature_id',
            'google_cid',
            'google_entity_id',
            'google_display_name',
            'latitude',
            'longitude',
            'google_resolution_source',
            'google_resolution_status',
            'google_resolution_fingerprint',
            'google_resolution_message',
            'formatted_address',
            'components',
            'metadata',
            'navigation_links',
        ]));

        $persistedAddress = SharedFormSchema::prepareAddressPersistenceData($formState['address']);
        $cid = GooglePlaceIdentity::canonicalCid($persistedAddress['google_cid'] ?? null)
            ?? GooglePlaceIdentity::cidFromFeatureId($persistedAddress['google_feature_id'] ?? null);
        $canonicalPlaceId = is_string($persistedAddress['google_place_id'] ?? null)
            ? trim($persistedAddress['google_place_id'])
            : null;

        if (($cid !== null || ($canonicalPlaceId !== null && $canonicalPlaceId !== ''))
            && app(EnsureUniqueContributionCreateAction::class)->institutionMapsIdentityTakenByOther(
                $cid,
                $canonicalPlaceId !== '' ? $canonicalPlaceId : null,
                (string) $this->entity->getKey(),
            )
        ) {
            throw ValidationException::withMessages([
                'data.address.google_place_id' => __('Another institution already uses this Google Maps place.'),
            ]);
        }

        return $formState;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function verifiedMapsSelection(): ?array
    {
        if (! is_string($this->verifiedMapsSelectionToken) || $this->verifiedMapsSelectionToken === '') {
            return null;
        }

        $selection = Cache::get($this->verifiedMapsSelectionCacheKey($this->verifiedMapsSelectionToken));

        return is_array($selection) ? $selection : null;
    }

    private function verifiedMapsSelectionCacheKey(?string $token): string
    {
        return 'institution-contribution-update:verified-maps:'.hash('sha256', session()->getId().'|'.$this->getId().'|'.($token ?? ''));
    }

    private function clearVerifiedMapsSelection(): void
    {
        if (is_string($this->verifiedMapsSelectionToken) && $this->verifiedMapsSelectionToken !== '') {
            Cache::forget($this->verifiedMapsSelectionCacheKey($this->verifiedMapsSelectionToken));
        }

        $this->verifiedMapsSelectionToken = null;
    }
}
