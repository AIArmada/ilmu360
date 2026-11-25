<?php

namespace App\Features\Contributions\EntryPoints\Livewire\Pages;

use AIArmada\Addressing\Actions\FetchGooglePlaceDetailsAction;
use AIArmada\Addressing\Actions\FetchGooglePlacePhotoPreviewsAction;
use AIArmada\Addressing\GooglePlaces\GooglePlaceIdentity;
use AIArmada\Addressing\GooglePlaces\GooglePlacesConfiguration;
use AIArmada\Addressing\Support\AddressFormState;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Contacting\Enums\SocialPlatform;
use AIArmada\FilamentContacting\Schemas\ContactMethodRepeaterSchema;
use App\Features\Contributions\Api\ContributionDirectUpdates;
use App\Features\Contributions\Api\ContributionRequestLifecycle;
use App\Features\Contributions\Api\ContributionRequestReader;
use App\Features\Contributions\Api\ContributionSubmissionPreparation;
use App\Features\Contributions\Api\ContributionUpdateContextReader;
use App\Features\Contributions\Api\Enums\ContributionSubjectType;
use App\Features\Contributions\Api\InstitutionMapsSelectionContext;
use App\Features\Contributions\Api\VerifiedInstitutionMapsSelection;
use App\Features\Contributions\Api\VerifiedInstitutionMapsSelections;
use App\Features\Contributions\Contracts\ContributionRequestGuard;
use App\Features\Contributions\EntryPoints\Filament\Forms\InstitutionContributionFormSchema;
use App\Features\Contributions\EntryPoints\Filament\Forms\PersonContributionFormSchema;
use App\Features\Contributions\EntryPoints\Filament\Forms\ReferenceContributionFormSchema;
use App\Features\Contributions\EntryPoints\Ui\ContributionSubjectPresenter;
use App\Features\Contributions\Models\ContributionRequest;
use App\Features\Contributions\Support\DonationQrFormUploads;
use App\Features\DonationChannels\Api\DonationQrWorkflow;
use App\Features\Events\Api\EventContributionStateMapper;
use App\Features\Events\Api\Filament\EventContributionFormContext;
use App\Features\Events\Api\Filament\EventContributionFormMode;
use App\Features\Events\Api\Filament\EventContributionFormSchema;
use App\Features\Events\Models\Event;
use App\Features\Institutions\Api\InstitutionMapsSelection;
use App\Features\Institutions\Models\Institution;
use App\Features\Persons\Models\Person;
use App\Features\References\Models\Reference;
use App\Models\User;
use App\Platform\Concurrency\MapsIdentityLock;
use App\Platform\Location\Ui\Concerns\InteractsWithLocationPickerSelection;
use App\Platform\Media\MediaFileNamer;
use App\Platform\Models\MorphModelResolver;
use App\Platform\Ui\Forms\AddressFields;
use App\Platform\Ui\Forms\DonationQrFields;
use App\Platform\Ui\InteractsWithToasts;
use App\Shared\Media\EventMediaForm;
use App\Shared\Signals\SignalRecorder;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;

#[Layout('layouts.app')]
class SuggestUpdate extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;
    use InteractsWithLocationPickerSelection;
    use InteractsWithToasts;
    use RestrictsFileUploadsToSchemaComponents;
    use WithFileUploads;

    #[Locked]
    public Event|Institution|Reference|Person $entity;

    #[Locked]
    public string $subjectType;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @var array<string, mixed> */
    #[Locked]
    public array $originalData = [];

    /** @var list<string> */
    #[Locked]
    public array $directEditMediaFields = [];

    private ?bool $directEditPermission = null;

    /** @var array{subject_label: string, redirect_url: string} */
    #[Locked]
    public array $subjectPresentation = [
        'subject_label' => '',
        'redirect_url' => '',
    ];

    #[Locked]
    public ?string $verifiedMapsSelectionToken = null;

    public function mount(
        string $subjectType,
        string $subjectId,
        ContributionUpdateContextReader $contributionUpdateContextReader,
        ContributionSubjectPresenter $contributionSubjectPresenter,
    ): void {
        // Directory contribution pages operate on globally visible records. Keep the
        // explicit global context active for the full Livewire render, including
        // deferred media-field hydration after mount returns.
        OwnerContext::setForRequest(null);

        OwnerContext::withOwner(null, function () use (
            $contributionSubjectPresenter,
            $contributionUpdateContextReader,
            $subjectId,
            $subjectType,
        ): void {
            $resolvedSubjectType = ContributionSubjectType::fromRouteSegment($subjectType);

            abort_unless($resolvedSubjectType instanceof ContributionSubjectType, 404);

            $this->subjectType = $resolvedSubjectType->value;

            $context = $contributionUpdateContextReader->resolve($this->subjectType, $subjectId);

            /** @var Event|Institution|Reference|Person $entity */
            $entity = MorphModelResolver::resolve($context['entity_type'])::query()->findOrFail($context['entity_id']);
            $this->entity = $entity;
            $this->originalData = $this->comparableOriginalData($context['initial_state']);
            $this->subjectPresentation = $contributionSubjectPresenter->present($this->entity);

            $user = auth()->user();

            abort_unless($user instanceof User, 403);
            abort_unless($user->can('view', $this->entity), 403);

            $this->directEditMediaFields = array_values(array_filter(
                $context['contract']['direct_edit_media_fields'] ?? [],
                static fn (string $field): bool => $field !== '',
            ));

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

            $form = $this->contributionForm()->fill($this->originalData);

            foreach (['description', 'bio'] as $key) {
                if (! array_key_exists($key, $this->originalData)) {
                    continue;
                }

                $field = $form->getComponentByStatePath($key);

                if ($field instanceof RichEditor) {
                    $this->originalData[$key] = $field->getState();
                }
            }
        });
    }

    #[Computed]
    public function canDirectEdit(): bool
    {
        if ($this->directEditPermission !== null) {
            return $this->directEditPermission;
        }

        $user = auth()->user();

        return $this->directEditPermission = $user instanceof User && $user->canPublishDirectoryUpdates();
    }

    #[Computed]
    public function pendingUpdateRequest(): ?ContributionRequest
    {
        $requestId = app(ContributionRequestReader::class)->pendingRequestIdForEntity(
            $this->entity->getMorphClass(),
            (string) $this->entity->getKey(),
        );

        return $requestId !== null ? ContributionRequest::query()->find($requestId) : null;
    }

    /**
     * @param  array<string, mixed>  $selection
     * @return array<string, mixed>
     */
    public function applyPlaceSelection(
        string $statePath,
        array $selection,
        InstitutionMapsSelection $institutionMapsSelection,
        VerifiedInstitutionMapsSelections $verifiedMapsSelections,
    ): array {
        $this->hitMapsThrottle('select');

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
        $selectionData = $institutionMapsSelection->resolve($placeId, $statePath.'.google_place_id');
        $merged = $institutionMapsSelection->merge(
            is_array($this->data) ? $this->data : [],
            $selectionData,
            $previousSelection,
        );
        $this->data = $merged['state'];
        $this->resetValidation('data.address.google_place_id');

        $token = Str::random(48);
        $previousToken = $this->verifiedMapsSelectionToken;
        $this->verifiedMapsSelectionToken = $token;
        $verifiedMapsSelections->replace(
            InstitutionMapsSelectionContext::update(),
            session()->getId(),
            $this->getId(),
            $token,
            VerifiedInstitutionMapsSelection::fromResolvedSelection($selectionData, $merged),
            $previousToken,
        );

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
        $this->hitMapsThrottle('photos');

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
                    ->helperText(fn (): string => $this->canDirectEdit()
                        ? __('Add a note to send this update for review. Leave it blank to publish immediately.')
                        : __('Optional: add context that helps maintainers review your update faster.'))
                    ->rows(4)
                    ->maxLength(2000),
            ]));
    }

    public function submit(
        ContributionDirectUpdates $directUpdates,
        ContributionSubmissionPreparation $submissionPreparation,
        ContributionRequestLifecycle $contributionRequestLifecycle,
        ContributionRequestGuard $contributionRequestGuard,
        MapsIdentityLock $mapsIdentityLockAction,
    ): void {
        OwnerContext::withOwner(null, function () use (
            $directUpdates,
            $submissionPreparation,
            $contributionRequestLifecycle,
            $contributionRequestGuard,
            $mapsIdentityLockAction,
        ): void {
            $user = auth()->user();

            abort_unless($user instanceof User, 403);

            if (! $user->canSubmitDirectoryFeedback()) {
                abort(403, $user->directoryFeedbackBanMessage());
            }

            // Fail expired QR uploads before getState(): Filament crashes on
            // missing temporary files, and anything persisted first would
            // strand partial state that blocks the contributor from retrying.
            $rawDonationRows = $this->data['donation_channels'] ?? null;
            DonationQrFormUploads::assertFresh($rawDonationRows);

            $formState = $this->contributionForm()->getState(shouldCallHooksBefore: false);
            $donationQrUploads = DonationQrFields::rekeyDonationRowsByRawKeys(
                DonationQrFields::collectDonationQrUploads($formState['donation_channels'] ?? null),
                $rawDonationRows,
            );
            $rawState = DonationQrFields::withoutDonationQrUploads($formState);
            $submittedDonationRows = DonationQrFields::rekeyDonationRowsByRawKeys(
                is_array($rawState['donation_channels'] ?? null) ? $rawState['donation_channels'] : [],
                $rawDonationRows,
            );

            $persist = function () use (
                $formState,
                $submittedDonationRows,
                $donationQrUploads,
                $directUpdates,
                $submissionPreparation,
                $contributionRequestLifecycle,
                $contributionRequestGuard,
                $user,
            ): void {
                $contributionRequestGuard->ensureNoPending(
                    $this->subjectType,
                    (string) $this->entity->getKey(),
                );

                $formState = $this->mergeVerifiedInstitutionMapsSelection($formState);
                $submissionState = $submissionPreparation->normalize($formState);
                $state = $this->normalizeSubmissionState($submissionState['state']);
                $changes = $submissionPreparation->changedPayload(
                    $state,
                    $this->originalData + app(EventContributionStateMapper::class)->provenanceOriginals($this->entity instanceof Event ? (string) $this->entity->getKey() : null),
                );
                $publishDirectly = $this->canDirectEdit() && $submissionState['proposer_note'] === null;
                $mediaUploads = $this->mediaUploads();
                $hasDirectEditMediaChange = $publishDirectly && $this->hasDirectEditMediaChange();

                if ($changes === [] && $mediaUploads === [] && ! $hasDirectEditMediaChange && $donationQrUploads === []) {
                    $this->addError('data', __('Make at least one change before continuing.'));

                    return;
                }

                if ($publishDirectly) {
                    $writeMedia = $hasDirectEditMediaChange || $mediaUploads !== [] || $donationQrUploads !== [];

                    $directUpdates->apply(
                        (string) $user->getKey(),
                        $this->entity->getMorphClass(),
                        (string) $this->entity->getKey(),
                        $changes,
                        $writeMedia ? function () use ($hasDirectEditMediaChange, $mediaUploads, $donationQrUploads, $submittedDonationRows): void {
                            $this->entity->refresh();

                            if ($hasDirectEditMediaChange || $mediaUploads !== []) {
                                $this->saveDirectEditMediaChanges();
                            }

                            if ($donationQrUploads !== []) {
                                app(DonationQrWorkflow::class)->attachDonationQrUploads(
                                    $this->entity->getMorphClass(),
                                    (string) $this->entity->getKey(),
                                    $submittedDonationRows,
                                    DonationQrFormUploads::prepare($donationQrUploads),
                                );
                            }
                        } : null,
                    );

                    $this->clearVerifiedMapsSelection();
                    $this->redirect($this->subjectPresentation['redirect_url'], navigate: true);

                    return;
                }

                $contributionRequestLifecycle->submitUpdate(
                    $this->entity->getMorphClass(),
                    (string) $this->entity->getKey(),
                    (string) $user->getKey(),
                    $changes,
                    $submissionState['proposer_note'],
                    function (string $requestId) use ($mediaUploads, $donationQrUploads, $submittedDonationRows): void {
                        $request = ContributionRequest::query()->findOrFail($requestId);
                        $this->stageMediaUploads($request, $mediaUploads);

                        if ($donationQrUploads !== []) {
                            $this->stageDonationQrUploads($request, $submittedDonationRows, $donationQrUploads);
                        }
                    },
                );

                $this->clearVerifiedMapsSelection();
                $this->redirect(route('contributions.index'), navigate: true);
            };

            if ($this->entity instanceof Institution) {
                try {
                    $mapsIdentityLockAction->handle($formState, $persist);
                } catch (LockTimeoutException) {
                    throw ValidationException::withMessages([
                        'data.address.google_place_id' => __('The review service is busy. Please try again in a moment.'),
                    ]);
                }

                return;
            }

            $persist();
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
        $event = $this->entity instanceof Event ? $this->entity : null;
        $context = new EventContributionFormContext(
            mode: EventContributionFormMode::Update,
            eventId: $event instanceof Event ? (string) $event->getKey() : null,
        );
        $schema = app(EventContributionFormSchema::class);

        $speakersAndMedia = [
            ...$schema->speakerComponents($context),
        ];

        if ($this->directEditMediaFields !== []) {
            $speakersAndMedia[] = EventMediaForm::section($this->directEditMediaFields, $this->configureVisitorMedia(...));
        }

        return [
            Tabs::make('EventUpdateTabs')
                ->id('event-update-tabs')
                ->columnSpanFull()
                ->tabs([
                    Tab::make(__('Majlis & Topik'))->icon('heroicon-o-document-text')->schema($schema->topicComponents($context)),
                    Tab::make(__('Tarikh, Masa & Kehadiran'))->icon('heroicon-o-clock')->schema([
                        ...$schema->scheduleComponents($context),
                        ...$schema->attendanceComponents($context),
                    ]),
                    Tab::make(__('Format, Penganjur & Lokasi'))->icon('heroicon-o-building-office')->schema([
                        ...$schema->organizerComponents($context),
                        ...$schema->locationComponents($context),
                    ]),
                    Tab::make(__('Penceramah & Media'))->icon('heroicon-o-user-group')->schema($speakersAndMedia),
                ]),
        ];
    }

    /**
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    private function personSubjectSchema(): array
    {
        return [
            PersonContributionFormSchema::publicTabs(
                id: 'person-update-tabs',
                mediaFields: $this->directEditMediaFields,
                configureMedia: $this->configureVisitorMedia(...),
            ),
        ];
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

        if (is_array($initialState['social_media'] ?? null)) {
            $initialState['social_media'] = array_map(static function (array $row): array {
                $urlOnly = in_array($row['platform'], [SocialPlatform::Website->value, SocialPlatform::Other->value], true);
                unset($row[$urlOnly ? 'handle' : 'url']);

                return $row;
            }, $initialState['social_media']);
        }

        if (! $this->entity instanceof Person) {
            return $initialState;
        }

        $personAddress = is_array($initialState['address'] ?? null)
            ? $initialState['address']
            : [];

        $initialState['address'] = AddressFields::expandStoredAreasForForm([
            'country_id' => AddressFormState::normalizeLocationId($personAddress['country_id'] ?? null),
            'state_id' => $personAddress['state_id'] ?? null,
            'city_id' => $personAddress['city_id'] ?? null,
            'state' => $personAddress['state'] ?? null,
            'city' => $personAddress['city'] ?? null,
            'area_assignments' => (array) ($personAddress['area_assignments'] ?? []),
        ]);

        $initialState['contactMethods'] = array_map(
            static function (mixed $contact): mixed {
                if (! is_array($contact)) {
                    return $contact;
                }

                return ContactMethodRepeaterSchema::normalizeRowsForComparison($contact);
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

        return InstitutionContributionFormSchema::components(
            creating: false,
            mediaFields: $this->directEditMediaFields,
            configureMedia: $this->configureVisitorMedia(...),
            mapsReselectionRequired: ! is_string($storedPlaceId) || trim($storedPlaceId) === '',
        );
    }

    /**
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    private function referenceSubjectSchema(): array
    {
        return ReferenceContributionFormSchema::components(
            includeMedia: true,
            mediaFields: $this->directEditMediaFields,
            configureMedia: $this->configureVisitorMedia(...),
        );
    }

    private function configureVisitorMedia(SpatieMediaLibraryFileUpload $field): void
    {
        if (! $this->canDirectEdit()) {
            $field->deletable(false);

            return;
        }

        $field->saveRelationshipsUsing(static function (SpatieMediaLibraryFileUpload $component): void {
            $component->saveUploadedFiles();
        });
    }

    private function hasDirectEditMediaChange(): bool
    {
        return array_any($this->directEditMediaFields, fn (string $field): bool => $this->directEditMediaFieldChanged($field));
    }

    /**
     * @return array<string, list<TemporaryUploadedFile>>
     */
    private function mediaUploads(): array
    {
        $uploads = [];

        foreach ($this->directEditMediaFields as $field) {
            $state = $this->directEditMediaField($field)?->getRawState();

            foreach (is_array($state) ? $state : [] as $file) {
                if ($file instanceof TemporaryUploadedFile) {
                    $uploads[$field][] = $file;
                }
            }
        }

        return $uploads;
    }

    private function directEditMediaFieldChanged(string $field): bool
    {
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

        $removals = [];

        foreach ($mediaIdsBeforeSave as $field => $mediaIds) {
            $mediaField = $this->directEditMediaField($field);
            if (! $mediaField instanceof SpatieMediaLibraryFileUpload) {
                continue;
            }

            $submittedIds = array_keys(is_array($mediaField->getRawState()) ? $mediaField->getRawState() : []);

            $this->entity->getMedia($field)
                ->filter(fn ($media): bool => isset($mediaIds[$media->uuid]) && ! in_array($media->uuid, $submittedIds, true))
                ->each(function ($media) use (&$removals): void {
                    $removals[] = $media->getKey();
                });
        }

        if ($removals === []) {
            return;
        }

        \Spatie\MediaLibrary\MediaCollections\Models\Media::query()
            ->whereKey($removals)
            ->get()
            ->each->delete();
    }

    /**
     * @param  array<string, list<TemporaryUploadedFile>>  $uploads
     */
    private function stageMediaUploads(ContributionRequest $request, array $uploads): void
    {
        foreach ($uploads as $fieldName => $files) {
            $field = $this->directEditMediaField($fieldName);

            foreach ($files as $file) {
                if (! $field instanceof SpatieMediaLibraryFileUpload || ! $file->exists()) {
                    throw ValidationException::withMessages(["data.{$fieldName}" => __('Upload the image again and retry.')]);
                }

                $request->addMediaFromString($file->get())
                    ->usingFileName($field->getUploadedFileNameForStorage($file))
                    ->usingName($field->getMediaName($file) ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))
                    ->withCustomProperties([
                        ...$field->getCustomProperties($file),
                        'contribution_field' => $fieldName,
                        'is_single' => ! $field->isMultiple(),
                    ])
                    ->toMediaCollection('pending_media');
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
        $media = app(DonationQrWorkflow::class);
        $failed = [];

        foreach ($uploads as $index => $file) {
            $row = $submittedRows[$index] ?? null;
            $fingerprint = is_array($row) ? $media->donationChannelFingerprint($row) : null;

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
                ->usingFileName($media->safeDonationQrFileName((string) $file->guessExtension(), MediaFileNamer::resolveBaseNameFromModel($request)))
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

        return app(EventContributionStateMapper::class)->toPersistenceState($state, (string) $this->entity->getKey());
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function eventComparableState(array $state): array
    {
        return app(EventContributionStateMapper::class)->toHelperState($state);
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
            // When Places is disabled the picker (and its field error block)
            // is not rendered, so also surface the failure as a form error.
            $message = __('Select and verify the institution on Google Maps before submitting.');
            $messages = ['data.address.google_place_id' => $message];

            if (! GooglePlacesConfiguration::isEnabled()) {
                $messages['data'] = $message;
            }

            throw ValidationException::withMessages($messages);
        }

        if ($submittedPlaceId === $originalPlaceId) {
            // The place is unchanged, so the verified Google identity block is
            // authoritative from the stored record: hidden form fields are
            // client-writable and must not be able to rewrite it.
            $formState['address'] = $this->restoreStoredMapsIdentity(
                is_array($formState['address'] ?? null) ? $formState['address'] : []
            );

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

        $persistedAddress = AddressFields::prepareAddressPersistenceData($formState['address']);
        $cid = GooglePlaceIdentity::canonicalCid($persistedAddress['google_cid'] ?? null)
            ?? GooglePlaceIdentity::cidFromFeatureId($persistedAddress['google_feature_id'] ?? null);
        $canonicalPlaceId = is_string($persistedAddress['google_place_id'] ?? null)
            ? trim($persistedAddress['google_place_id'])
            : null;

        if (($cid !== null || ($canonicalPlaceId !== null && $canonicalPlaceId !== ''))
            && app(ContributionSubmissionPreparation::class)->institutionMapsIdentityTakenByOther(
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
     * Overwrite the client-submitted Google identity block with the stored
     * values. Only keys present on the stored address are restored, so a
     * record without a resolved place never gains fabricated values.
     *
     * @param  array<string, mixed>  $formAddress
     * @return array<string, mixed>
     */
    private function restoreStoredMapsIdentity(array $formAddress): array
    {
        $originalAddress = data_get($this->originalData, 'address');

        if (! is_array($originalAddress)) {
            return $formAddress;
        }

        foreach ([
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
        ] as $key) {
            if (array_key_exists($key, $originalAddress)) {
                $formAddress[$key] = $originalAddress[$key];
            }
        }

        return $formAddress;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function verifiedMapsSelection(): ?array
    {
        if (! is_string($this->verifiedMapsSelectionToken) || $this->verifiedMapsSelectionToken === '') {
            return null;
        }

        return app(VerifiedInstitutionMapsSelections::class)
            ->get(
                InstitutionMapsSelectionContext::update(),
                session()->getId(),
                $this->getId(),
                $this->verifiedMapsSelectionToken,
            )
            ?->toArray();
    }

    private function clearVerifiedMapsSelection(): void
    {
        if (is_string($this->verifiedMapsSelectionToken) && $this->verifiedMapsSelectionToken !== '') {
            app(VerifiedInstitutionMapsSelections::class)->forget(
                InstitutionMapsSelectionContext::update(),
                session()->getId(),
                $this->getId(),
                $this->verifiedMapsSelectionToken,
            );
        }

        $this->verifiedMapsSelectionToken = null;
    }

    /**
     * Rate-limit paid Google Maps calls per contributor. Human selection pace
     * stays far below the cap; only abusive automation trips it.
     */
    private function hitMapsThrottle(string $action): void
    {
        $key = 'maps-'.$action.':'.(auth()->id() ?? session()->getId());

        if (RateLimiter::tooManyAttempts($key, 30)) {
            throw ValidationException::withMessages([
                'data.address.google_place_id' => __('Please wait a moment before trying again.'),
            ]);
        }

        RateLimiter::hit($key, 60);
    }
}
