<?php

namespace App\Livewire\Pages\Contributions;

use App\Actions\Contributions\CancelContributionRequestAction;
use App\Actions\Contributions\ResolveOwnContributionRequestAction;
use App\Enums\ContributionRequestType;
use App\Enums\MemberSubjectType;
use App\Livewire\Concerns\InteractsWithToasts;
use App\Models\ContributionRequest;
use App\Models\EventSubmission;
use App\Models\Institution;
use App\Models\Report;
use App\Models\Speaker;
use App\Models\User;
use App\Support\Location\AddressHierarchyFormatter;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
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

        return $user->contributionRequests()
            ->where('type', ContributionRequestType::Create->value)
            ->with(['entity', 'reviewer'])
            ->latest('created_at')
            ->paginate(perPage: 5, pageName: 'my_requests_page');
    }

    /**
     * @return LengthAwarePaginator<int, ContributionRequest>
     */
    #[Computed]
    public function myUpdateRequests(): LengthAwarePaginator
    {
        /** @var User $user */
        $user = auth()->user();

        return $user->contributionRequests()
            ->where('type', ContributionRequestType::Update->value)
            ->with(['entity', 'reviewer'])
            ->latest('created_at')
            ->paginate(perPage: 5, pageName: 'my_update_requests_page');
    }

    /**
     * @return LengthAwarePaginator<int, EventSubmission>
     */
    #[Computed]
    public function submittedEvents(): LengthAwarePaginator
    {
        /** @var User $user */
        $user = auth()->user();

        return EventSubmission::query()
            ->where('submitted_by', $user->id)
            ->whereHas('event', function (Builder $query) use ($user): void {
                $query->where(function (Builder $eventQuery) use ($user): void {
                    $eventQuery->whereNull('institution_id')
                        ->orWhereDoesntHave('institution.members', function (Builder $memberQuery) use ($user): void {
                            $memberQuery->where('users.id', $user->getKey());
                        });
                });
            })
            ->with([
                'event' => fn ($query) => $query->with(['institution', 'speakers', 'references']),
            ])
            ->latest('created_at')
            ->paginate(perPage: 5, pageName: 'submitted_events_page');
    }

    /**
     * @return LengthAwarePaginator<int, Report>
     */
    #[Computed]
    public function myReports(): LengthAwarePaginator
    {
        /** @var User $user */
        $user = auth()->user();

        return $user->reports()
            ->with(['entity', 'handler'])
            ->latest('created_at')
            ->paginate(perPage: 5, pageName: 'my_reports_page');
    }

    /**
     * @return list<string>
     */
    public function eventSubmissionDetails(EventSubmission $submission): array
    {
        $event = $submission->event;
        $details = [];

        if (filled($event->institution?->display_name)) {
            $details[] = __('Institution: :name', ['name' => $event->institution->display_name]);
        }

        if ($event->speakers->isNotEmpty()) {
            $details[] = __('Speakers: :names', ['names' => $event->speakers->pluck('formatted_name')->join(', ')]);
        }

        if ($event->references->isNotEmpty()) {
            $details[] = __('References: :names', ['names' => $event->references->pluck('title')->join(', ')]);
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
                                MemberSubjectType::Speaker->value => __('Penceramah'),
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
                            ->helperText(__('Hanya rekod institusi dan penceramah yang aktif dipaparkan di sini.')),
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

    public function startMembershipClaim(): void
    {
        $state = $this->claimEntryForm()->getState();
        $subjectType = MemberSubjectType::tryFrom((string) ($state['subject_type'] ?? ''));
        $subjectSlug = is_string($state['subject_slug'] ?? null) ? $state['subject_slug'] : null;

        if (! $subjectType?->isClaimable() || ! filled($subjectSlug)) {
            $this->addError('claimData.subject_slug', __('Select a record before continuing.'));

            return;
        }

        $this->redirectRoute('membership-claims.create', [
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
                ->where('status', 'verified')
                ->where('is_active', true)
                ->tap(fn (Builder $query): Builder => filled($search) ? $query->searchNameOrNickname($search) : $query)
                ->with(['address.state', 'address.district', 'address.subdistrict'])
                ->orderBy('name')
                ->limit(50)
                ->get(['id', 'slug', 'name', 'nickname'])
                ->mapWithKeys(fn (Institution $institution): array => [$institution->slug => $this->institutionMembershipClaimLabel($institution)])
                ->all(),
            MemberSubjectType::Speaker => Speaker::query()
                ->where('status', 'verified')
                ->where('is_active', true)
                ->tap(fn (Builder $query): Builder => $this->applySearchConstraint($query, 'name', $search))
                ->orderBy('name')
                ->limit(50)
                ->get()
                ->mapWithKeys(fn (Speaker $speaker): array => [$speaker->slug => $speaker->formatted_name])
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
            MemberSubjectType::Institution => $this->resolveInstitutionMembershipClaimOptionLabel($subjectSlug),
            MemberSubjectType::Speaker => Speaker::query()
                ->where('status', 'verified')
                ->where('is_active', true)
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

        return $query->where($column, $this->databaseLikeOperator(), "%{$normalizedSearch}%");
    }

    private function databaseLikeOperator(): string
    {
        return config('database.default') === 'pgsql' ? 'ILIKE' : 'LIKE';
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized !== '' ? $normalized : null;
    }

    private function institutionMembershipClaimLabel(Institution $institution): string
    {
        $location = AddressHierarchyFormatter::format($institution->address);

        if ($location === '') {
            return $institution->display_name;
        }

        return "{$institution->display_name} - {$location}";
    }

    private function resolveInstitutionMembershipClaimOptionLabel(string $subjectSlug): ?string
    {
        $institution = Institution::query()
            ->where('status', 'verified')
            ->where('is_active', true)
            ->where('slug', $subjectSlug)
            ->with(['address.state', 'address.district', 'address.subdistrict'])
            ->first(['id', 'name', 'nickname']);

        if (! $institution instanceof Institution) {
            return null;
        }

        return $this->institutionMembershipClaimLabel($institution);
    }

    private function normalizeActiveTab(string $value): string
    {
        return in_array($value, ['events', 'contributions', 'updates', 'reports', 'membership'], true)
            ? $value
            : 'events';
    }
}
