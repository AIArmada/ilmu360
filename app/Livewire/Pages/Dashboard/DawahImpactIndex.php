<?php

namespace App\Livewire\Pages\Dashboard;

use App\Data\ShareTracking\ShareTrackingLinkData;
use App\Data\ShareTracking\ShareTrackingOutcomeData;
use App\Enums\DawahShareOutcomeType;
use App\Enums\DawahShareSubjectType;
use App\Models\User;
use App\Services\ShareTrackingAnalyticsService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class DawahImpactIndex extends Component
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

        foreach (DawahShareSubjectType::cases() as $type) {
            $options[$type->value] = match ($type) {
                DawahShareSubjectType::Event => __('Events'),
                DawahShareSubjectType::Institution => __('Institutions'),
                DawahShareSubjectType::Speaker => __('Speakers'),
                DawahShareSubjectType::Series => __('Series'),
                DawahShareSubjectType::Reference => __('References'),
                DawahShareSubjectType::Search => __('Search Results'),
                DawahShareSubjectType::Page => __('Pages'),
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

        foreach (DawahShareOutcomeType::cases() as $type) {
            $options[$type->value] = match ($type) {
                DawahShareOutcomeType::Signup => __('Signups'),
                DawahShareOutcomeType::EventRegistration => __('Event registrations'),
                DawahShareOutcomeType::EventCheckin => __('Event check-ins'),
                DawahShareOutcomeType::EventSubmission => __('Event submissions'),
                DawahShareOutcomeType::EventSave => __('Event saves'),
                DawahShareOutcomeType::EventGoing => __('Going responses'),
                DawahShareOutcomeType::InstitutionFollow => __('Institution follows'),
                DawahShareOutcomeType::SpeakerFollow => __('Speaker follows'),
                DawahShareOutcomeType::SeriesFollow => __('Series follows'),
                DawahShareOutcomeType::ReferenceFollow => __('Reference follows'),
                DawahShareOutcomeType::SavedSearchCreated => __('Saved searches created'),
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
            'active' => __('Active Links'),
            'inactive' => __('Inactive Links'),
        ];
    }

    /**
     * @return array{
     *     outbound_shares: int,
     *     visits: int,
     *     unique_visitors: int,
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

    /**
     * @return Collection<int, array{
     *     provider: string,
     *     label: string,
     *     outbound_shares: int,
     *     visits: int,
     *     unique_visitors: int,
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
        return $this->analytics()->subjectSummariesForUser($this->currentUser());
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
        return $this->analytics()->topSubjectsForUser($this->currentUser());
    }

    /**
     * @return Collection<int, ShareTrackingLinkData>
     */
    #[Computed]
    public function topLinks(): Collection
    {
        return $this->analytics()
            ->linksForUser($this->currentUser(), 'visits')
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
        $links = $this->analytics()->linksForUser(
            $this->currentUser(),
            $this->sort,
            $this->subjectType,
            $this->status,
            $this->outcomeType,
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

        return collect(DawahShareSubjectType::cases())
            ->contains(fn (DawahShareSubjectType $type): bool => $type->value === $subjectType)
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

        return collect(DawahShareOutcomeType::cases())
            ->contains(fn (DawahShareOutcomeType $type): bool => $type->value === $outcomeType)
            ? $outcomeType
            : 'all';
    }

    public function render(): View
    {
        return view('livewire.pages.dashboard.dawah-impact-index');
    }
}
