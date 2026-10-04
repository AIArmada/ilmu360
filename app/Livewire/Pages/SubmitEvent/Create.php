<?php

namespace App\Livewire\Pages\SubmitEvent;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventSession;
use AIArmada\FilamentEvents\Resources\EventResource;
use App\Actions\Events\SubmitFrontendEventAction;
use App\Contracts\EventCategoryCatalog;
use App\Data\Events\SubmitEventFormContext;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventPrayerTime;
use App\Enums\EventTaxonomyCode;
use App\Enums\EventVisibility;
use App\Enums\TaxonomyTerm\DomainTermCode;
use App\Livewire\Concerns\InteractsWithLocationPickerSelection;
use App\Models\Event;
use App\Models\EventSubmission;
use App\Models\Institution;
use App\Models\Language;
use App\Models\User;
use App\Services\Ai\EventMediaExtractionService;
use App\States\EventStatus\Approved;
use App\States\EventStatus\Cancelled;
use App\States\EventStatus\EventStatus;
use App\States\EventStatus\Pending;
use App\Support\Events\OrganizerResolver;
use App\Support\Submission\EntitySubmissionAccess;
use App\Support\Submission\SubmissionTimingPolicy;
use App\Support\Submission\SubmitEventOptionsProvider;
use App\Support\Submission\SubmitEventPrefill;
use Closure;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;
use Spatie\MediaLibrary\HasMedia;
use Throwable;

#[Layout('layouts.app')]
class Create extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;
    use InteractsWithLocationPickerSelection;
    use WithFileUploads;

    public function render(): View
    {
        return view('components.pages.submit-event.create');
    }

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function boot(): void
    {
        OwnerContext::setForRequest(null);
    }

    public function updatedData(mixed $value, ?string $key = null): void
    {
        if ($key === 'event_category_ids' && EventSubmissionFormSchema::hasCommunityCategorySelection($value)) {
            $this->data['event_format'] = EventFormat::Physical->value;
        }

        $this->refreshPrayerPreview($key);
    }

    /**
     * Prayer-clock hints for the end-time validation. Cache-only and
     * flag-gated like submit: form keystrokes must never perform live
     * HTTP, and hints must never disagree with persisted timing.
     * Best-effort: any failure leaves the previous hint (or none) so
     * hints never break input.
     */
    private function refreshPrayerPreview(?string $key): void
    {
        if (! in_array($key, ['event_date', 'submission_country_id', 'submission_timezone', 'event_format', 'location_type', 'location_institution_id', 'location_venue_id', 'location_same_as_institution', 'primary_organizer_id'], true)) {
            return;
        }

        if (! config('prayer.enabled')) {
            unset($this->data['prayer_preview']);

            return;
        }

        try {
            $preview = $this->buildPrayerPreviewFromState($this->data ?? []);
        } catch (Throwable) {
            return;
        }

        if ($preview === null) {
            unset($this->data['prayer_preview']);

            return;
        }

        $this->data['prayer_preview'] = json_encode($preview);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>|null
     */
    private function buildPrayerPreviewFromState(array $state): ?array
    {
        // Shared with end-time validation so hints and the comparison
        // read the same clocks from the same state mapping.
        return app(SubmissionTimingPolicy::class)->freshPrayerPreview($state);
    }

    #[Url(as: 'step')]
    public ?string $wizardStep = null;

    #[Locked]
    public ?string $eventId = null;

    #[Locked]
    public ?string $eventOccurrenceId = null;

    #[Locked]
    public ?string $duplicateEventId = null;

    #[Locked]
    public ?string $prefillPersonId = null;

    #[Locked]
    public ?string $scopedInstitutionId = null;

    public ?TemporaryUploadedFile $event_source_attachment = null;

    protected ?Institution $resolvedScopedInstitution = null;

    protected ?string $resolvedScopedInstitutionKey = null;

    protected ?Event $resolvedEventContainer = null;

    protected ?string $resolvedEventContainerKey = null;

    protected function eventForm(): Schema
    {
        return $this->getForm('form') ?? throw new RuntimeException('Submit event form is not available.');
    }

    public function mount(): void
    {
        $this->eventId = $this->requestedEventId(request()->query('event'));
        $this->duplicateEventId = $this->requestedEventId(request()->query('duplicate'));
        $this->prefillPersonId = $this->resolvePrefilledPersonId(request()->query('person'));
        $scopedInstitution = $this->resolveScopedInstitution(request()->query('institution'));
        $defaultLanguageId = Language::where('code', 'ms')->value('id');
        $mountOptions = app(SubmitEventOptionsProvider::class);
        $defaultCategoryId = $mountOptions->defaultEventTermId(EventCategoryCatalog::TAXONOMY_CODE, 'kuliah_ceramah');
        $defaultDomainId = $mountOptions->defaultEventTermId(EventTaxonomyCode::Domain->value, DomainTermCode::AgamaKerohanian->value);
        $defaultIsReligious = $defaultDomainId !== null;

        if ($scopedInstitution instanceof Institution) {
            $this->scopedInstitutionId = $scopedInstitution->id;
            $this->resolvedScopedInstitution = $scopedInstitution;
            $user = $this->submitterUser();
            $this->resolvedScopedInstitutionKey = $user instanceof User
                ? $user->getKey().':'.$scopedInstitution->id
                : null;
        }

        $this->eventOccurrenceId = $this->resolveRequestedOccurrenceId(request()->query('occurrence'));

        $state = [
            'submitter_name' => auth()->user()?->name,
            'submitter_email' => auth()->user()?->email,
            'event_category_ids' => $defaultCategoryId,
            'domain_tags' => $defaultDomainId,
            'persons' => $this->prefillPersonId !== null ? [$this->prefillPersonId] : [],
            'children_allowed' => true,
            'gender' => EventGenderRestriction::All->value,
            'age_group' => [EventAgeGroup::AllAges->value],
            'languages' => $defaultLanguageId !== null ? [(string) $defaultLanguageId] : [],
            'prayer_time' => $defaultIsReligious
                ? EventPrayerTime::SelepasMaghrib->value
                : EventPrayerTime::LainWaktu->value,
            'custom_time' => $defaultIsReligious ? null : EventSubmissionFormSchema::DEFAULT_SUBMISSION_TIME,
            'event_format' => EventFormat::Physical->value,
            'visibility' => EventVisibility::Public->value,
            'primary_organizer_kind' => 'institution',
            'location_same_as_institution' => true,
            'location_type' => 'institution',
            'is_muslim_only' => false,
            'other_key_people' => [],
            'captcha_token' => null,
            'submission_country_id' => $mountOptions->defaultSubmissionCountryId(),
        ];

        if (($eventContainer = $this->selectedEventContainer()) instanceof Event) {
            $state = array_replace($state, SubmitEventPrefill::containerDefaults(
                $eventContainer,
                $this->submitterUser(),
                is_string($state['submission_country_id'] ?? null) ? $state['submission_country_id'] : null,
            ));
            $state['event_occurrence_id'] = $this->eventOccurrenceId
                ?? $mountOptions->defaultOccurrenceId($eventContainer);
        }

        if (($duplicateEvent = $this->selectedDuplicateEvent()) instanceof Event) {
            $duplicateDefaults = SubmitEventPrefill::duplicateDefaults(
                $duplicateEvent,
                $this->submitterUser(),
                is_string($state['submission_country_id'] ?? null) ? $state['submission_country_id'] : null,
                includePrivatePeople: $this->canViewPrivateDuplicateDetails($duplicateEvent),
            );
            $state = array_replace($state, $duplicateDefaults);
        }

        if ($scopedInstitution instanceof Institution) {
            $state = array_replace($state, SubmitEventPrefill::scopedDefaults($scopedInstitution));
        }

        if ($this->prefillPersonId !== null) {
            $state['persons'] = collect([
                ...((array) ($state['persons'] ?? [])),
                $this->prefillPersonId,
            ])->filter()->unique()->values()->all();
        }

        if (! array_key_exists('submission_timezone', $state)) {
            $timing = app(SubmissionTimingPolicy::class);
            $mountCountryId = is_string($state['submission_country_id'] ?? null) ? $state['submission_country_id'] : null;
            $state['submission_timezone'] = $timing->defaultSubmissionTimezone(
                $timing->resolveSubmissionCountryId($mountCountryId),
            );
        }

        $this->eventForm()->fill($state);
    }

    protected function resolveScopedInstitution(mixed $institutionId): ?Institution
    {
        if ($institutionId === null || $institutionId === '') {
            return null;
        }

        abort_unless(is_string($institutionId) && Str::isUuid($institutionId), 404);

        $user = $this->submitterUser();

        abort_unless($user instanceof User, 403);

        $institution = app(EntitySubmissionAccess::class)
            ->memberInstitutionQueryForSubmitter($user)
            ->whereKey($institutionId)
            ->first();

        abort_unless($institution instanceof Institution, 403);

        return $institution;
    }

    protected function scopedInstitution(): ?Institution
    {
        $institutionId = $this->scopedInstitutionId;

        if (! is_string($institutionId) || ! Str::isUuid($institutionId)) {
            $this->resolvedScopedInstitution = null;
            $this->resolvedScopedInstitutionKey = null;

            return null;
        }

        // Re-authorize on every request: mount-time membership must not
        // survive revocation, deletion, or a forged Livewire update. The memo
        // is keyed by actor + institution so test actor swaps never reuse a
        // stale authorization.
        $user = $this->submitterUser();

        if (! $user instanceof User) {
            $this->resolvedScopedInstitution = null;
            $this->resolvedScopedInstitutionKey = null;

            return null;
        }

        $memoKey = $user->getKey().':'.$institutionId;

        if ($this->resolvedScopedInstitution instanceof Institution
            && $this->resolvedScopedInstitutionKey === $memoKey
        ) {
            return $this->resolvedScopedInstitution;
        }

        $institution = app(EntitySubmissionAccess::class)
            ->memberInstitutionQueryForSubmitter($user)
            ->whereKey($institutionId)
            ->first();

        if (! $institution instanceof Institution) {
            $this->resolvedScopedInstitution = null;
            $this->resolvedScopedInstitutionKey = null;

            return null;
        }

        $this->resolvedScopedInstitution = $institution;
        $this->resolvedScopedInstitutionKey = $memoKey;

        return $institution;
    }

    protected function hasScopedInstitution(): bool
    {
        return $this->scopedInstitution() instanceof Institution;
    }

    public function extractEventFromMedia(EventMediaExtractionService $eventMediaExtractionService): void
    {
        $this->assertSubmissionNotRateLimited('extract', 'event_source_attachment');

        $maxFileSizeKb = (int) config('ai.features.event_media_extraction.max_file_size_kb', 10240);
        $acceptedMimeTypes = config('ai.features.event_media_extraction.accepted_mime_types', [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
        ]);

        if (! is_array($acceptedMimeTypes) || $acceptedMimeTypes === []) {
            $acceptedMimeTypes = [
                'application/pdf',
                'image/jpeg',
                'image/png',
                'image/webp',
            ];
        }

        $validated = Validator::make(
            ['event_source_attachment' => $this->event_source_attachment],
            [
                'event_source_attachment' => [
                    'required',
                    'file',
                    "max:{$maxFileSizeKb}",
                    'mimetypes:'.implode(',', $acceptedMimeTypes),
                ],
            ],
            [
                'event_source_attachment.required' => __('Sila muat naik poster, gambar, atau PDF terlebih dahulu.'),
                'event_source_attachment.file' => __('Fail yang dimuat naik tidak sah.'),
                'event_source_attachment.max' => __('Saiz fail melebihi had yang dibenarkan.'),
                'event_source_attachment.mimetypes' => __('Hanya fail PDF, JPEG, PNG, atau WEBP dibenarkan.'),
            ],
        )->validate();

        /** @var TemporaryUploadedFile $file */
        $file = $validated['event_source_attachment'];

        try {
            $extractedState = $eventMediaExtractionService->extract($file);
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->title(__('Pengekstrakan AI gagal. Sila cuba semula.'))
                ->danger()
                ->send();

            return;
        }

        $mergedState = array_replace($this->data ?? [], $extractedState);
        $mergedState['event_category_ids'] = $this->firstSelection($mergedState['event_category_ids'] ?? null);
        $mergedState['domain_tags'] = $this->firstSelection($mergedState['domain_tags'] ?? null);
        $mergedState['age_group'] = EventSubmissionFormSchema::normalizeAgeGroupState($mergedState['age_group'] ?? []);

        if (
            in_array(EventAgeGroup::Children->value, $mergedState['age_group'], true) ||
            in_array(EventAgeGroup::AllAges->value, $mergedState['age_group'], true)
        ) {
            $mergedState['children_allowed'] = true;
        }

        $mimeType = (string) $file->getMimeType();

        if (str_starts_with($mimeType, 'image/')) {
            $sourceRatio = $this->uploadedImageAspectRatio($file);

            if ($sourceRatio === '3:4' && blank($mergedState['poster'] ?? null)) {
                $mergedState['poster'] = $file;
            }

            if ($sourceRatio === '16:9' && blank($mergedState['cover'] ?? null)) {
                $mergedState['cover'] = $file;
            }
        }

        $this->eventForm()->fill($mergedState);

        $wizard = $this->eventForm()->getComponent(
            fn (mixed $component): bool => $component instanceof Wizard
        );

        if ($wizard instanceof Wizard) {
            $steps = array_values($wizard->getChildSchema()->getComponents());
            $lastStep = end($steps);

            if ($lastStep instanceof Step && filled($lastStep->getKey())) {
                $wizard->goToStep($lastStep->getKey());
            }
        }

        Notification::make()
            ->title(__('Maklumat majlis berjaya diekstrak dengan AI.'))
            ->success()
            ->send();
    }

    private function uploadedImageAspectRatio(TemporaryUploadedFile $file): ?string
    {
        $path = $file->getRealPath();

        if ($path === '') {
            return null;
        }

        $dimensions = @getimagesize($path);

        if (! is_array($dimensions)) {
            return null;
        }

        $width = $dimensions[0];
        $height = $dimensions[1];

        return match (true) {
            $width > 0 && $height > 0 && abs(($width / $height) - (16 / 9)) < 0.01 => '16:9',
            $width > 0 && $height > 0 && abs(($width / $height) - (3 / 4)) < 0.01 => '3:4',
            default => null,
        };
    }

    public function form(Schema $schema): Schema
    {
        // Context is resolved fresh on every request via the component
        // guards; the schema never retains the component instance.
        $context = new SubmitEventFormContext(
            scopedInstitution: $this->scopedInstitution(),
            eventContainer: $this->selectedEventContainer(),
            submitter: $this->submitterUser(),
            requestedOccurrenceId: $this->eventOccurrenceId,
        );

        return (new EventSubmissionFormSchema($context, new SubmitEventOptionsProvider))
            ->form($schema, $this->wizardStep);
    }

    public function submit(): mixed
    {
        $this->assertSubmissionNotRateLimited('submit', 'data.captcha_token');

        if (
            is_string($this->scopedInstitutionId) && $this->scopedInstitutionId !== ''
            && ! $this->scopedInstitution() instanceof Institution
        ) {
            throw ValidationException::withMessages([
                'data.scoped_institution_id' => __('Anda tidak dibenarkan menghantar bagi pihak institusi ini.'),
            ]);
        }

        $state = $this->eventForm()->getState();
        // The UI canonical for category/topic is a single select (scalar),
        // while the action/validator contract is a list. Normalize from the
        // raw form state: getState() casts array payloads on single selects
        // to null, so the dehydrated value cannot be trusted here.
        $state['event_category_ids'] = EventSubmissionFormSchema::normalizeEventCategoryState($this->data['event_category_ids'] ?? null);
        $state['domain_tags'] = EventSubmissionFormSchema::normalizeDomainTagState($this->data['domain_tags'] ?? null);
        $state['age_group'] = EventSubmissionFormSchema::normalizeAgeGroupSelection(
            EventSubmissionFormSchema::normalizeAgeGroupState($state['age_group'] ?? []),
        );

        if (($duplicateEvent = $this->selectedDuplicateEvent()) instanceof Event) {
            $state['visibility'] = SubmitEventPrefill::duplicateVisibility($duplicateEvent);
        }

        if (
            in_array(EventAgeGroup::Children->value, $state['age_group'], true) ||
            in_array(EventAgeGroup::AllAges->value, $state['age_group'], true)
        ) {
            $state['children_allowed'] = true;
        }

        $state['captcha_token'] = $this->data['captcha_token'] ?? null;

        $eventContainer = $this->selectedEventContainer();
        $result = app(SubmitFrontendEventAction::class)->handle(
            state: $state,
            request: request(),
            submitter: $this->submitterUser(),
            eventContainer: $eventContainer,
            scopedInstitution: $this->scopedInstitution(),
            persistRelationships: function (HasMedia $model): void {
                if (! $model instanceof Model) {
                    throw new RuntimeException('Submit-event media persistence requires an Eloquent model, '.get_class($model).' given.');
                }

                // References are owned once in the persist action for both
                // events and sessions; only media uploads persist here.
                $schema = $this->eventForm()->model($model);

                foreach (['cover', 'poster', 'gallery'] as $field) {
                    $component = $schema->getComponent($field, withHidden: true);

                    if ($component instanceof SpatieMediaLibraryFileUpload) {
                        $component->saveRelationships();
                    }
                }
            },
            validationKeyPrefix: 'data.',
        );

        $event = $result['event'];

        $session = $result['session'];

        session()->flash('event_title', $session instanceof EventSession ? $session->title : $event->title);
        session()->flash('event_slug', $event->slug);
        session()->flash('event_status', (string) $event->status);
        session()->flash('event_parent_visibility', $event->visibility->value);
        session()->flash('event_occurrence_visibility', $session?->occurrence?->visibility);
        session()->flash('event_session_id', $session?->getKey());
        session()->flash('event_session_slug', $session?->slug);
        session()->flash('event_occurrence_slug', $session?->occurrence?->slug);
        session()->flash('event_auto_approved', $result['auto_approved']);
        session()->flash('submission_institution_id', $this->scopedInstitutionId);
        session()->flash('event_visibility', $result['visibility']);

        if ($eventContainer instanceof Event) {
            session()->flash('event_container_id', $eventContainer->id);
            session()->flash('event_container_title', $eventContainer->title);
        }

        return redirect()->route('submit-event.success');
    }

    /**
     * Method-level throttle for the Livewire submit and AI-extraction paths,
     * which the GET route throttle does not cover. Limits reuse the
     * configured `event-submission` limiter so web and API stay aligned.
     */
    protected function assertSubmissionNotRateLimited(string $action, string $field): void
    {
        $limiter = RateLimiter::limiter('event-submission');
        $limit = $limiter instanceof Closure ? $limiter(request()) : null;
        $maxAttempts = $limit instanceof Limit ? (int) $limit->maxAttempts : 5;
        $decaySeconds = $limit instanceof Limit ? (int) $limit->decaySeconds : 3600;
        $key = 'submit-event:'.$action.':'.(string) request()->ip();

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            throw ValidationException::withMessages([
                $field => __('Terlalu banyak percubaan. Sila cuba semula dalam :seconds saat.', [
                    'seconds' => RateLimiter::availableIn($key),
                ]),
            ]);
        }

        RateLimiter::hit($key, $decaySeconds);
    }

    protected function selectedEventContainer(): ?Event
    {
        $eventId = $this->eventId;

        if ($eventId === null || $eventId === '') {
            $this->resolvedEventContainer = null;
            $this->resolvedEventContainerKey = null;

            return null;
        }

        $user = $this->submitterUser();
        $memoKey = ($user instanceof User ? (string) $user->getKey() : 'guest').':'.(string) $eventId;

        if ($this->resolvedEventContainer instanceof Event
            && $this->resolvedEventContainerKey === $memoKey
        ) {
            return $this->resolvedEventContainer;
        }

        // A requested container that is malformed, deleted, or unauthorized
        // fails closed instead of silently becoming a new event.
        if (! Str::isUuid($eventId)) {
            abort(404);
        }

        $event = Event::query()
            ->with(['institution:id,name', 'accessPolicy'])
            ->find($eventId);

        if (! $event instanceof Event) {
            abort(404);
        }

        if (! $user instanceof User || ! $user->can('update', $event)) {
            abort(403);
        }

        $scopedInstitution = $this->scopedInstitution();

        if (
            $scopedInstitution instanceof Institution
            && ! $this->eventMatchesScopedInstitution($event, $scopedInstitution)
        ) {
            abort(403);
        }

        $this->resolvedEventContainer = $event;
        $this->resolvedEventContainerKey = $memoKey;

        return $event;
    }

    protected function eventMatchesScopedInstitution(Event $event, Institution $institution): bool
    {
        // The location institution is not ownership; only the primary
        // organizer establishes the institutional scope.
        return OrganizerResolver::involvementMatchesInstitution(
            $event->primaryOrganizerInvolvement,
            $institution,
        );
    }

    protected function resolveRequestedOccurrenceId(mixed $occurrenceId): ?string
    {
        if ($occurrenceId === null || (is_string($occurrenceId) && trim($occurrenceId) === '')) {
            return null;
        }

        if (! is_string($occurrenceId) || ! Str::isUuid($occurrenceId)) {
            abort(404);
        }

        $container = $this->selectedEventContainer();

        if (! $container instanceof Event) {
            abort(404);
        }

        $occurrence = EventOccurrence::query()
            ->whereKey($occurrenceId)
            ->where('event_id', $container->getKey())
            ->first();

        if (! $occurrence instanceof EventOccurrence || ! $this->isEligibleSessionOccurrence($occurrence)) {
            abort(404);
        }

        return (string) $occurrence->getKey();
    }

    protected function isEligibleSessionOccurrence(EventOccurrence $occurrence): bool
    {
        return ! in_array(
            (string) $occurrence->status,
            [EventOccurrence::CANCELLED, EventOccurrence::COMPLETED, EventOccurrence::ARCHIVED],
            true,
        );
    }

    protected function selectedDuplicateEvent(): ?Event
    {
        $duplicateId = $this->duplicateEventId;

        if (! is_string($duplicateId) || ! Str::isUuid($duplicateId)) {
            return null;
        }

        $duplicateEvent = Event::query()
            ->with([
                'classifications',
                'references.parentReference.parentReference',
                'languages',
                'persons',
                'keyPeople.person',
            ])
            ->find($duplicateId);

        abort_unless($duplicateEvent instanceof Event && $this->canDuplicateSourceEvent($duplicateEvent), 404);

        return $duplicateEvent;
    }

    protected function canDuplicateSourceEvent(Event $event): bool
    {
        if ($this->canViewPrivateDuplicateDetails($event)) {
            return true;
        }

        $eventVisibility = $event->visibility instanceof EventVisibility
            ? $event->visibility->value
            : (string) $event->visibility;

        return $event->published_at !== null
            && $this->isPubliclyVisibleDuplicateStatus($event)
            && in_array($eventVisibility, [EventVisibility::Public->value, EventVisibility::Unlisted->value], true);
    }

    protected function canViewPrivateDuplicateDetails(Event $event): bool
    {
        $user = $this->submitterUser();

        return $user instanceof User && ($user->can('update', $event) || $this->isDuplicateEventOwner($event));
    }

    protected function isDuplicateEventOwner(Event $event): bool
    {
        $user = $this->submitterUser();

        if (! $user instanceof User) {
            return false;
        }

        return EventSubmission::query()
            ->where('event_id', $event->id)
            ->where('submitter_type', $user->getMorphClass())
            ->where('submitter_id', $user->id)
            ->exists();
    }

    protected function isPubliclyVisibleDuplicateStatus(Event $event): bool
    {
        $status = $event->status;

        if ($status instanceof EventStatus) {
            return $status->equals(Approved::class)
                || $status->equals(Pending::class)
                || $status->equals(Cancelled::class);
        }

        return in_array((string) $status, Event::PUBLIC_STATUSES, true);
    }

    public function eventManagementUrl(): ?string
    {
        $event = $this->selectedEventContainer();

        return $event instanceof Event
            ? EventResource::getUrl('view', ['record' => $event], panel: 'ahli')
            : null;
    }

    private function firstSelection(mixed $state): mixed
    {
        if ($state instanceof Collection) {
            return $state->first();
        }

        return is_array($state) ? ($state[0] ?? null) : $state;
    }

    private function requestedEventId(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        abort_unless(is_string($value) && Str::isUuid($value), 404);

        return $value;
    }

    protected function submitterUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    protected function resolvePrefilledPersonId(mixed $personId): ?string
    {
        if (! is_string($personId) || ! Str::isUuid($personId)) {
            return null;
        }

        return app(EntitySubmissionAccess::class)->canUsePerson(
            $this->submitterUser(),
            $personId,
        ) ? $personId : null;
    }

    public function formProgress(): int
    {
        $requiredFields = EventSubmissionFormSchema::requiredFieldProgressChecks(
            $this->data ?? [],
            $this->hasScopedInstitution(),
            auth()->check(),
        );
        $completed = count(array_filter($requiredFields));

        return (int) round(($completed / count($requiredFields)) * 100);
    }

    /**
     * @return array{
     *     has_scoped_institution: bool,
     *     is_authenticated: bool,
     *     speaker_required_category_ids: list<string>,
     *     multi_timezone_country_ids: list<string>,
     * }
     */
    public function clientProgressConfiguration(): array
    {
        return [
            'speaker_required_category_ids' => app(SubmitEventOptionsProvider::class)->speakerRequiredCategoryIds(),
            'multi_timezone_country_ids' => app(SubmitEventOptionsProvider::class)->multiTimezoneCountryIds(),
            'has_scoped_institution' => $this->hasScopedInstitution(),
            'is_authenticated' => auth()->check(),
        ];
    }
}
