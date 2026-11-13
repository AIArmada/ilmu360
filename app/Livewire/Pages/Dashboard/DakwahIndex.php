<?php

namespace App\Livewire\Pages\Dashboard;

use App\Data\ShareTracking\ShareTrackingLinkData;
use App\Data\ShareTracking\ShareTrackingOutcomeData;
use App\Shared\ShareTracking\ShareOutcomeType;
use App\Enums\ShareSubjectType;
use App\Models\User;
use App\Services\ShareTrackingAnalyticsService;
use App\Support\ShareTracking\ShareTrackingMeasurement;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * @property-read Collection<int, ShareTrackingLinkData> $linkLibrary
 * @property-read string $measurementStatus
 */
#[Layout('layouts.app')]
class DakwahIndex extends Component
{
    use WithPagination;

    #[Url(as: 'type')]
    public string $subjectType = 'all';

    #[Url(as: 'sort')]
    public string $sort = 'recent';

    #[Url(as: 'status')]
    public string $status = 'all';

    #[Url(as: 'outcome')]
    public string $outcomeType = 'all';

    public function mount(): void
    {
        $this->subjectType = $this->normalizeSubjectType($this->subjectType);
        $this->sort = $this->normalizeSort($this->sort);
        $this->status = $this->normalizeStatus($this->status);
        $this->outcomeType = $this->normalizeOutcomeType($this->outcomeType);
    }

    public function updatedSubjectType(string $subjectType): void
    {
        $this->subjectType = $this->normalizeSubjectType($subjectType);
        $this->resetPage('links_page');
    }

    public function updatedSort(string $sort): void
    {
        $this->sort = $this->normalizeSort($sort);
        $this->resetPage('links_page');
    }

    public function updatedStatus(string $status): void
    {
        $this->status = $this->normalizeStatus($status);
        $this->resetPage('links_page');
    }

    public function updatedOutcomeType(string $outcomeType): void
    {
        $this->outcomeType = $this->normalizeOutcomeType($outcomeType);
        $this->resetPage('links_page');
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function subjectTypeOptions(): array
    {
        $options = ['all' => __('All Shared Pages')];

        foreach (ShareSubjectType::cases() as $type) {
            $options[$type->value] = match ($type) {
                ShareSubjectType::Event => __('Events'),
                ShareSubjectType::Institution => __('Institutions'),
                ShareSubjectType::Person => __('Speakers'),
                ShareSubjectType::Series => __('Series'),
                ShareSubjectType::Reference => __('References'),
                ShareSubjectType::Search => __('Search Results'),
                ShareSubjectType::Page => __('Pages'),
            };
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function sortOptions(): array
    {
        return [
            'recent' => __('Most Recent Activity'),
            'visits' => __('Most Visits'),
            'signups' => __('Most Signups'),
            'registrations' => __('Most Registrations'),
            'checkins' => __('Most Event Check-ins'),
            'submissions' => __('Most Event Submissions'),
        ];
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function outcomeTypeOptions(): array
    {
        $options = ['all' => __('All Responses')];

        foreach (ShareOutcomeType::cases() as $type) {
            $options[$type->value] = match ($type) {
                ShareOutcomeType::Signup => __('Signups'),
                ShareOutcomeType::EventRegistration => __('Event registrations'),
                ShareOutcomeType::EventCheckin => __('Event check-ins'),
                ShareOutcomeType::EventSubmission => __('Event submissions'),
                ShareOutcomeType::EventSave => __('Event saves'),
                ShareOutcomeType::EventGoing => __('Going responses'),
                ShareOutcomeType::InstitutionFollow => __('Institution follows'),
                ShareOutcomeType::PersonFollow => __('Person follows'),
                ShareOutcomeType::SeriesFollow => __('Series follows'),
                ShareOutcomeType::ReferenceFollow => __('Reference follows'),
                ShareOutcomeType::SavedSearchCreated => __('Saved searches created'),
            };
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function statusOptions(): array
    {
        return [
            'all' => __('All Links'),
            'active' => __('Recently active'),
            'inactive' => __('No recent activity'),
        ];
    }

    /**
     * @return array{
     *     outbound_shares: int,
     *     visits: int,
     *     distinct_browsers: int,
     *     signups: int,
     *     event_registrations: int,
     *     event_checkins: int,
     *     event_submissions: int,
     *     total_outcomes: int
     * }
     */
    #[Computed]
    public function impactSummary(): array
    {
        return $this->analytics()->summaryForUser($this->currentUser());
    }

    #[Computed]
    public function measurementStatus(): string
    {
        return ShareTrackingMeasurement::status();
    }

    /**
     * @return Collection<int, array{
     *     provider: string,
     *     label: string,
     *     outbound_shares: int,
     *     visits: int,
     *     distinct_browsers: int,
     *     outcomes: int,
     *     signups: int,
     *     event_registrations: int,
     *     event_checkins: int,
     *     event_submissions: int
     * }>
     */
    #[Computed]
    public function providerBreakdown(): Collection
    {
        return $this->analytics()->providerBreakdownForUser($this->currentUser());
    }

    /**
     * @return Collection<int, array{
     *     subject_type: string,
     *     label: string,
     *     links: int,
     *     visits: int,
     *     signups: int,
     *     event_registrations: int,
     *     event_checkins: int,
     *     event_submissions: int,
     *     total_outcomes: int
     * }>
     */
    #[Computed]
    public function subjectSummaries(): Collection
    {
        return $this->analytics()->subjectSummariesForUser($this->currentUser(), $this->linkLibrary);
    }

    /**
     * @return Collection<int, array{
     *     subject_type: string,
     *     subject_key: string,
     *     title_snapshot: string,
     *     type_label: string,
     *     links: int,
     *     visits: int,
     *     signups: int,
     *     event_registrations: int,
     *     event_checkins: int,
     *     event_submissions: int,
     *     total_outcomes: int
     * }>
     */
    #[Computed]
    public function topSubjects(): Collection
    {
        return $this->analytics()->topSubjectsForUser($this->currentUser(), links: $this->linkLibrary);
    }

    /**
     * @return Collection<int, ShareTrackingLinkData>
     */
    #[Computed]
    public function topLinks(): Collection
    {
        return $this->linkLibrary
            ->sortByDesc('visitsCount')
            ->take(3)
            ->values();
    }

    /**
     * @return Collection<int, ShareTrackingOutcomeData>
     */
    #[Computed]
    public function recentResponses(): Collection
    {
        return $this->analytics()->recentOutcomesForUser($this->currentUser(), 12);
    }

    /**
     * @return LengthAwarePaginator<int, ShareTrackingLinkData>
     */
    #[Computed]
    public function links(): LengthAwarePaginator
    {
        $links = $this->subjectType === 'all' && $this->sort === 'recent' && $this->status === 'all' && $this->outcomeType === 'all'
            ? $this->linkLibrary
            : $this->analytics()->linksForUser(
                $this->currentUser(),
                $this->measurementStatus === 'not_configured' ? 'recent' : $this->sort,
                $this->subjectType,
                $this->measurementStatus === 'not_configured' ? 'all' : $this->status,
                $this->measurementStatus === 'not_configured' ? 'all' : $this->outcomeType,
            );

        $perPage = 12;
        $page = Paginator::resolveCurrentPage('links_page');

        return new Paginator(
            $links->forPage($page, $perPage)->values(),
            $links->count(),
            $perPage,
            $page,
            [
                'pageName' => 'links_page',
                'path' => request()->url(),
            ],
        );
    }

    /** @return Collection<int, ShareTrackingLinkData> */
    #[Computed]
    public function linkLibrary(): Collection
    {
        return $this->analytics()->linksForUser($this->currentUser());
    }

    protected function analytics(): ShareTrackingAnalyticsService
    {
        return app(ShareTrackingAnalyticsService::class);
    }

    protected function currentUser(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    protected function normalizeSubjectType(string $subjectType): string
    {
        if ($subjectType === 'all') {
            return $subjectType;
        }

        return collect(ShareSubjectType::cases())
            ->contains(fn (ShareSubjectType $type): bool => $type->value === $subjectType)
            ? $subjectType
            : 'all';
    }

    protected function normalizeSort(string $sort): string
    {
        return in_array($sort, ['recent', 'visits', 'signups', 'registrations', 'checkins', 'submissions'], true)
            ? $sort
            : 'recent';
    }

    protected function normalizeStatus(string $status): string
    {
        return in_array($status, ['all', 'active', 'inactive'], true)
            ? $status
            : 'all';
    }

    protected function normalizeOutcomeType(string $outcomeType): string
    {
        if ($outcomeType === 'all') {
            return $outcomeType;
        }

        return collect(ShareOutcomeType::cases())
            ->contains(fn (ShareOutcomeType $type): bool => $type->value === $outcomeType)
            ? $outcomeType
            : 'all';
    }

    public function render(): View
    {
        return view('livewire.pages.dashboard.dakwah-index');
    }
}
