<?php

namespace App\Features\Contributions\EntryPoints\Livewire\Pages;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Features\Contributions\Api\ContributionRequestLifecycle;
use App\Features\Contributions\Api\ContributionRequestReader;
use App\Features\Contributions\Enums\ContributionRequestType;
use App\Features\Contributions\Models\ContributionRequest;
use App\Features\Events\Api\Data\EventSubmissionPage;
use App\Features\Events\Api\EventSubmissions;
use App\Features\Institutions\Api\InstitutionLookup;
use App\Features\Membership\Api\MemberSubjectType;
use App\Features\Persons\Api\PersonLookup;
use App\Features\Reports\Api\ReportPage;
use App\Features\Reports\Api\ReportReader;
use App\Features\Reports\Api\ReportSnapshot;
use App\Models\User;
use App\Platform\Ui\InteractsWithToasts;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
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
    use RestrictsFileUploadsToSchemaComponents;
    use WithPagination;

    #[Url(as: 'section', except: 'events')]
    public string $activeTab = 'events';

    /** @var array<string, string> */
    public array $reviewNotes = [];

    /** @var array<string, string> */
    public array $rejectionReasons = [];

    /** @var array<string, mixed>|null */
    public ?array $membershipApplicationData = [];

    public function mount(): void
    {
        abort_unless(auth()->user() instanceof User, 403);

        $this->activeTab = $this->normalizeActiveTab($this->activeTab);

        $this->membershipApplicationForm()->fill([
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
        $requestIds = app(ContributionRequestReader::class)->requestIdsForProposer((string) $user->getKey());

        return OwnerContext::withOwner(null, fn (): LengthAwarePaginator => ContributionRequest::query()
            ->whereKey($requestIds)
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
        $requestIds = app(ContributionRequestReader::class)->requestIdsForProposer((string) $user->getKey());

        return OwnerContext::withOwner(null, fn (): LengthAwarePaginator => ContributionRequest::query()
            ->whereKey($requestIds)
            ->where('type', ContributionRequestType::Update->value)
            ->with(['entity', 'reviewer'])
            ->latest('created_at')
            ->paginate(perPage: 5, pageName: 'my_update_requests_page'));
    }

    /** @return LengthAwarePaginator<int, \App\Features\Events\Api\Data\EventSubmissionCard> */
    #[Computed]
    public function submittedEvents(): LengthAwarePaginator
    {
        /** @var User $user */
        $user = auth()->user();
        $page = OwnerContext::withOwner(null, fn (): EventSubmissionPage => app(EventSubmissions::class)->paginateForSubmitter(
            (string) $user->getKey(),
            5,
            'submitted_events_page',
        ));

        return new LengthAwarePaginator($page->items, $page->total, $page->perPage, $page->currentPage, [
            'pageName' => 'submitted_events_page',
            'path' => Paginator::resolveCurrentPath(),
            'query' => request()->query(),
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, ReportSnapshot>
     */
    #[Computed]
    public function myReports(): LengthAwarePaginator
    {
        /** @var User $user */
        $user = auth()->user();
        $page = max((int) request()->query('my_reports_page', 1), 1);

        $reportPage = OwnerContext::withOwner(
            null,
            fn (): ReportPage => app(ReportReader::class)->pageForReporter(
                (string) $user->getKey(),
                5,
                $page,
            ),
        );

        return new LengthAwarePaginator(
            $reportPage->items,
            $reportPage->total,
            $reportPage->perPage,
            $reportPage->currentPage,
            [
                'pageName' => 'my_reports_page',
                'path' => Paginator::resolveCurrentPath(),
                'query' => request()->query(),
            ],
        );
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('membershipApplicationData')
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
                            ->getSearchResultsUsing(fn (Get $get, string $search): array => $this->membershipApplicationSearchOptions(
                                subjectType: $this->normalizeNullableString($get('subject_type')),
                                search: $search,
                            ))
                            ->getOptionLabelUsing(fn (mixed $value, Get $get): ?string => $this->membershipApplicationOptionLabel(
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
        ContributionRequestLifecycle $contributionRequestLifecycle,
        ContributionRequestReader $contributionRequestReader,
    ): void {
        /** @var User $user */
        $user = auth()->user();
        $request = ContributionRequest::query()->with('entity')->findOrFail(
            $contributionRequestReader->findRequestIdForProposer((string) $user->getKey(), $requestId)
        );

        $contributionRequestLifecycle->cancel((string) $request->getKey(), (string) $user->getKey());
    }

    public function startMembershipApplication(): void
    {
        $state = $this->membershipApplicationForm()->getState();
        $subjectType = MemberSubjectType::tryFrom((string) ($state['subject_type'] ?? ''));
        $subjectSlug = is_string($state['subject_slug'] ?? null) ? $state['subject_slug'] : null;

        if (! $subjectType?->supportsMembershipApplication() || ! filled($subjectSlug)) {
            $this->addError('membershipApplicationData.subject_slug', __('Select a record before continuing.'));

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

    protected function membershipApplicationForm(): Schema
    {
        return $this->getForm('form') ?? throw new RuntimeException('Membership claim entry form is not available.');
    }

    /**
     * @return array<string, string>
     */
    private function membershipApplicationSearchOptions(?string $subjectType, string $search): array
    {
        return match (MemberSubjectType::tryFrom((string) $subjectType)) {
            MemberSubjectType::Institution => app(InstitutionLookup::class)->membershipApplicationOptions($search),
            MemberSubjectType::Person => app(PersonLookup::class)->membershipApplicationOptions($search),
            default => [],
        };
    }

    private function membershipApplicationOptionLabel(?string $subjectType, ?string $subjectSlug): ?string
    {
        if (! filled($subjectSlug)) {
            return null;
        }

        return match (MemberSubjectType::tryFrom((string) $subjectType)) {
            MemberSubjectType::Institution => app(InstitutionLookup::class)->membershipApplicationLabel($subjectSlug),
            MemberSubjectType::Person => app(PersonLookup::class)->membershipApplicationLabel($subjectSlug),
            default => null,
        };
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeActiveTab(string $value): string
    {
        return in_array($value, ['events', 'contributions', 'updates', 'reports', 'membership'], true)
            ? $value
            : 'events';
    }
}
