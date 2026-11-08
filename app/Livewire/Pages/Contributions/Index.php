<?php

namespace App\Livewire\Pages\Contributions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Models\EventInvolvement;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventReference;
use App\Actions\Contributions\Queries\ResolveOwnContributionRequestAction;
use App\Actions\Contributions\Submission\CancelContributionRequestAction;
use App\Enums\ContributionRequestType;
use App\Enums\EventKeyPersonRole;
use App\Enums\InstitutionStatus;
use App\Enums\MemberSubjectType;
use App\Models\ContributionRequest;
use App\Models\Event;
use App\Models\EventSubmission;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use App\Models\Report;
use App\Models\User;
use App\Platform\Ui\InteractsWithToasts;
use App\Support\Events\PublicSchedulePolicy;
use App\Support\Events\PublicScheduleSlug;
use App\Support\Location\AddressHierarchyFormatter;
use App\Support\Timezone\UserDateTimeFormatter;
use App\Support\Timezone\UserTimezoneResolver;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

#[Layout('layouts.app')]
#[Title('My Contributions')]
class Index extends Component implements HasForms
{
    use InteractsWithForms;
    use InteractsWithToasts;
    use WithPagination;

    #[Url(as: 'section', except: 'events')]
    public string $activeTab = 'events';

    /** @var array<string, string> */
    public array $reviewNotes = [];

    /** @var array<string, string> */
    public array $rejectionReasons = [];

    /** @var array<string, mixed>|null */
    public ?array $claimData = [];

    public function mount(): void
    {
        abort_unless(auth()->user() instanceof User, 403);

        $this->activeTab = $this->normalizeActiveTab($this->activeTab);

        $this->claimEntryForm()->fill([
            'subject_type' => MemberSubjectType::Institution->value,
            'subject_slug' => null,
        ]);
    }

    public function updatedActiveTab(string $value): void
    {
        $this->activeTab = $this->normalizeActiveTab($value);
    }

    /**
     * @return LengthAwarePaginator<int, ContributionRequest>
     */
    #[Computed]
    public function myRequests(): LengthAwarePaginator
    {
        /** @var User $user */
        $user = auth()->user();

        return OwnerContext::withOwner(null, fn (): LengthAwarePaginator => $user->contributionRequests()
            ->where('type', ContributionRequestType::Create->value)
            ->with(['entity', 'reviewer'])
            ->latest('created_at')
            ->paginate(perPage: 5, pageName: 'my_requests_page'));
    }

    /**
     * @return LengthAwarePaginator<int, ContributionRequest>
     */
    #[Computed]
    public function myUpdateRequests(): LengthAwarePaginator
    {
        /** @var User $user */
        $user = auth()->user();

        return OwnerContext::withOwner(null, fn (): LengthAwarePaginator => $user->contributionRequests()
            ->where('type', ContributionRequestType::Update->value)
            ->with(['entity', 'reviewer'])
            ->latest('created_at')
            ->paginate(perPage: 5, pageName: 'my_update_requests_page'));
    }

    /**
     * @return LengthAwarePaginator<int, EventSubmission>
     */
    #[Computed]
    public function submittedEvents(): LengthAwarePaginator
    {
        /** @var User $user */
        $user = auth()->user();

        return OwnerContext::withOwner(null, fn (): LengthAwarePaginator => EventSubmission::query()
            ->where('submitter_type', $user->getMorphClass())
            ->where('submitter_id', $user->id)
            ->whereHas('event', function (Builder $query) use ($user): void {
                $institutionIdSelector = $this->eventInstitutionIdSelector();

                $query->where(function (Builder $eventQuery) use ($institutionIdSelector, $user): void {
                    $eventQuery->whereRaw("{$institutionIdSelector} is null")
                        ->orWhereRaw(
                            "not exists (
                                select 1
                                from institution_members
                                where institution_members.institution_id = {$institutionIdSelector}
                                  and institution_members.user_id = ?
                            )",
                            [$user->getKey()],
                        );
                });
            })
            ->with([
                'event' => fn ($query) => $query->with(['institution', 'persons', 'references']),
                'occurrence' => fn ($query) => $query->with([
                    'involvements.involveable',
                    'references.referenceable',
                ]),
            ])
            ->latest('created_at')
            ->paginate(perPage: 5, pageName: 'submitted_events_page'));
    }

    /**
     * @return LengthAwarePaginator<int, Report>
     */
    #[Computed]
    public function myReports(): LengthAwarePaginator
    {
        /** @var User $user */
        $user = auth()->user();

        return OwnerContext::withOwner(null, fn (): LengthAwarePaginator => $user->reports()
            ->with(['entity', 'handler'])
            ->latest('created_at')
            ->paginate(perPage: 5, pageName: 'my_reports_page'));
    }

    /**
     * @return array{
     *     title: string|null,
     *     parent_title: string|null,
     *     status_value: string|null,
     *     status_label: string|null,
     *     date_label: string|null,
     *     time_label: string|null,
     *     url: string|null
     * }
     */
    public function eventSubmissionPresentation(EventSubmission $submission): array
    {
        $event = $submission->event;
        $occurrence = $submission->occurrence;

        $title = $occurrence instanceof EventOccurrence && filled($occurrence->title)
            ? (string) $occurrence->title
            : ($event instanceof Event ? (string) $event->title : null);

        $parentTitle = null;

        if (
            $occurrence instanceof EventOccurrence
            && $event instanceof Event
            && filled($event->title)
            && (string) $event->title !== $title
        ) {
            $parentTitle = (string) $event->title;
        }

        $status = $occurrence instanceof EventOccurrence
            ? $occurrence->status
            : ($event instanceof Event ? $event->status : null);
        $statusValue = $status !== null ? (string) $status : null;
        $statusLabel = match (true) {
            $status instanceof HasLabel => (string) $status->getLabel(),
            $status !== null && method_exists($status, 'label') => (string) $status->label(),
            $statusValue !== null => str($statusValue)->headline()->toString(),
            default => null,
        };

        $startsAt = $occurrence instanceof EventOccurrence
            ? $occurrence->starts_at
            : ($event instanceof Event ? $event->starts_at : null);
        $endsAt = $occurrence instanceof EventOccurrence
            ? $occurrence->ends_at
            : ($event instanceof Event ? $event->ends_at : null);

        $dateLabel = $startsAt !== null
            ? UserDateTimeFormatter::translatedFormat($startsAt, 'l, j F Y')
            : null;
        $timeLabel = null;

        if ($startsAt !== null) {
            $timeLabel = UserDateTimeFormatter::format($startsAt, 'h:i A');

            if ($endsAt !== null) {
                $viewerTimezone = UserTimezoneResolver::resolve();
                $sameDay = $startsAt->copy()->timezone($viewerTimezone)->isSameDay($endsAt->copy()->timezone($viewerTimezone));
                $timeLabel .= ' – '.UserDateTimeFormatter::format($endsAt, $sameDay ? 'h:i A' : 'j M, h:i A');
            }
        }

        $url = null;

        if ($event instanceof Event && $event->isPubliclyReachable()) {
            if ($occurrence instanceof EventOccurrence) {
                if (PublicSchedulePolicy::isPublicOccurrence($occurrence)) {
                    $url = route('events.occurrence', [$event, PublicScheduleSlug::occurrence($occurrence)]);
                }
            } else {
                $url = route('events.show', $event);
            }
        }

        return [
            'title' => $title,
            'parent_title' => $parentTitle,
            'status_value' => $statusValue,
            'status_label' => $statusLabel,
            'date_label' => $dateLabel,
            'time_label' => $timeLabel,
            'url' => $url,
        ];
    }

    /**
     * @return list<string>
     */
    public function eventSubmissionDetails(EventSubmission $submission): array
    {
        $event = $submission->event;
        $occurrence = $submission->occurrence;

        if (! $occurrence instanceof EventOccurrence && ! $event instanceof Event) {
            return [];
        }

        $institution = null;
        $speakers = collect();
        $references = collect();

        if ($occurrence instanceof EventOccurrence) {
            $organizer = $occurrence->involvements
                ->firstWhere('role_code', 'organizer')
                ?->involveable;

            $institution = $organizer instanceof Institution ? $organizer : null;

            $speakers = $occurrence->involvements
                ->where('role_code', EventKeyPersonRole::Speaker->value)
                ->map(function (EventInvolvement $involvement): ?string {
                    $person = $involvement->involveable;

                    if ($person instanceof Person) {
                        return $person->formatted_name;
                    }

                    return filled($involvement->display_name) ? (string) $involvement->display_name : null;
                })
                ->filter()
                ->values();

            $references = $occurrence->references
                ->map(function (EventReference $reference): ?string {
                    $catalogReference = $reference->referenceable;

                    if ($catalogReference instanceof Reference) {
                        return $catalogReference->displayTitle();
                    }

                    return filled($reference->title) ? (string) $reference->title : null;
                })
                ->filter()
                ->values();
        }

        if ($event instanceof Event) {
            $institution ??= $event->institution;

            if ($speakers->isEmpty()) {
                $speakers = $event->persons->pluck('formatted_name');
            }

            if ($references->isEmpty()) {
                $references = $event->references
                    ->map(fn (Reference $reference): string => $reference->displayTitle());
            }
        }

        $details = [];

        if ($institution instanceof Institution && filled($institution->display_name)) {
            $details[] = __('Institution: :name', ['name' => $institution->display_name]);
        }

        if ($speakers->isNotEmpty()) {
            $details[] = __('Speakers: :names', ['names' => $speakers->join(', ')]);
        }

        if ($references->isNotEmpty()) {
            $details[] = __('References: :names', ['names' => $references->join(', ')]);
        }

        return $details;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('claimData')
            ->components([
                Section::make(__('Claim Membership'))
                    ->description(__('Cari institusi atau penceramah yang anda urus, kemudian teruskan ke borang tuntutan.'))
                    ->schema([
                        Select::make('subject_type')
                            ->label(__('Record Type'))
                            ->options([
                                MemberSubjectType::Institution->value => __('Institusi'),
                                MemberSubjectType::Person->value => __('Penceramah'),
                            ])
                            ->required()
                            ->columnSpan(1)
                            ->live()
                            ->afterStateUpdated(function (Set $set): void {
                                $set('subject_slug', null);
                            }),
                        Select::make('subject_slug')
                            ->label(__('Record'))
                            ->placeholder(__('Search by name'))
                            ->required()
                            ->searchable()
                            ->getSearchResultsUsing(fn (Get $get, string $search): array => $this->membershipClaimSearchOptions(
                                subjectType: $this->normalizeNullableString($get('subject_type')),
                                search: $search,
                            ))
                            ->getOptionLabelUsing(fn (mixed $value, Get $get): ?string => $this->membershipClaimOptionLabel(
                                subjectType: $this->normalizeNullableString($get('subject_type')),
                                subjectSlug: is_string($value) ? $value : null,
                            ))
                            ->columnSpan(2)
                            ->helperText(__('Hanya rekod institusi dan orang yang aktif dipaparkan di sini.')),
                    ])
                    ->columns([
                        'default' => 1,
                        'md' => 3,
                    ]),
            ]);
    }

    public function cancel(
        string $requestId,
        CancelContributionRequestAction $cancelContributionRequestAction,
        ResolveOwnContributionRequestAction $resolveOwnContributionRequestAction,
    ): void {
        /** @var User $user */
        $user = auth()->user();
        $request = $resolveOwnContributionRequestAction->handle($user, $requestId);

        $cancelContributionRequestAction->handle($request, $user);
    }

    public function startMembershipApplication(): void
    {
        $state = $this->claimEntryForm()->getState();
        $subjectType = MemberSubjectType::tryFrom((string) ($state['subject_type'] ?? ''));
        $subjectSlug = is_string($state['subject_slug'] ?? null) ? $state['subject_slug'] : null;

        if (! $subjectType?->isClaimable() || ! filled($subjectSlug)) {
            $this->addError('claimData.subject_slug', __('Select a record before continuing.'));

            return;
        }

        $this->redirectRoute('membership-applications.create', [
            'subjectType' => $subjectType->publicRouteSegment(),
            'subjectId' => $subjectSlug,
        ], navigate: true);
    }

    public function render(): View
    {
        return view('livewire.pages.contributions.index');
    }

    protected function claimEntryForm(): Schema
    {
        return $this->getForm('form') ?? throw new RuntimeException('Membership claim entry form is not available.');
    }

    /**
     * @return array<string, string>
     */
    private function membershipClaimSearchOptions(?string $subjectType, string $search): array
    {
        return match (MemberSubjectType::tryFrom((string) $subjectType)) {
            MemberSubjectType::Institution => Institution::query()
                ->where('status', InstitutionStatus::Verified->value)
                ->whereIn('status', InstitutionStatus::publiclyVisibleValues())
                ->tap(fn (Builder $query): Builder => filled($search) ? $query->searchNameOrNickname($search) : $query)
                ->with(['addresses'])
                ->orderBy('name')
                ->limit(50)
                ->with('names')->get(['id', 'slug', 'name'])
                ->mapWithKeys(fn (Institution $institution): array => [(string) $institution->getKey() => $this->institutionMembershipApplicationLabel($institution)])
                ->all(),
            MemberSubjectType::Person => Person::query()
                ->where('status', 'verified')
                ->whereIn('status', ['verified', 'pending'])
                ->tap(fn (Builder $query): Builder => $this->applySearchConstraint($query, 'name', $search))
                ->orderBy('name')
                ->limit(50)
                ->get()
                ->mapWithKeys(fn (Person $person): array => [(string) $person->slug => $person->formatted_name])
                ->all(),
            default => [],
        };
    }

    private function membershipClaimOptionLabel(?string $subjectType, ?string $subjectSlug): ?string
    {
        if (! filled($subjectSlug)) {
            return null;
        }

        return match (MemberSubjectType::tryFrom((string) $subjectType)) {
            MemberSubjectType::Institution => $this->resolveInstitutionMembershipApplicationOptionLabel($subjectSlug),
            MemberSubjectType::Person => Person::query()
                ->where('status', 'verified')
                ->whereIn('status', ['verified', 'pending'])
                ->where('slug', $subjectSlug)
                ->first()?->formatted_name,
            default => null,
        };
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function applySearchConstraint(Builder $query, string $column, string $search): Builder
    {
        $normalizedSearch = trim($search);

        if ($normalizedSearch === '') {
            return $query;
        }

        return $query->whereLike($column, "%{$normalizedSearch}%");
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized !== '' ? $normalized : null;
    }

    private function eventInstitutionIdSelector(): string
    {
        return 'events.institution_id';
    }

    private function institutionMembershipApplicationLabel(Institution $institution): string
    {
        $location = AddressHierarchyFormatter::format($institution->primaryAddress());

        if ($location === '') {
            return $institution->display_name;
        }

        return "{$institution->display_name} - {$location}";
    }

    private function resolveInstitutionMembershipApplicationOptionLabel(string $subjectId): ?string
    {
        $institution = Institution::query()
            ->where('status', InstitutionStatus::Verified->value)
            ->whereIn('status', InstitutionStatus::publiclyVisibleValues())
            ->whereKey($subjectId)
            ->with(['addresses'])
            ->with('names')->first(['id', 'name']);

        if (! $institution instanceof Institution) {
            return null;
        }

        return $this->institutionMembershipApplicationLabel($institution);
    }

    private function normalizeActiveTab(string $value): string
    {
        return in_array($value, ['events', 'contributions', 'updates', 'reports', 'membership'], true)
            ? $value
            : 'events';
    }
}
