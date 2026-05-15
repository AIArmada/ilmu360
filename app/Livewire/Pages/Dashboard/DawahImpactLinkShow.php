<?php

namespace App\Livewire\Pages\Dashboard;

use App\Data\ShareTracking\ShareTrackingLinkData;
use App\Data\ShareTracking\ShareTrackingOutcomeData;
use App\Data\ShareTracking\ShareTrackingVisitData;
use App\Models\User;
use App\Services\ShareTrackingAnalyticsService;
use App\Services\ShareTrackingService;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class DawahImpactLinkShow extends Component
{
    public string $linkId;

    public function mount(string $link): void
    {
        abort_unless($this->analytics()->findLinkForUser($this->currentUser(), $link) instanceof ShareTrackingLinkData, 404);

        $this->linkId = $link;
    }

    #[Computed]
    public function linkData(): ShareTrackingLinkData
    {
        $resolvedLink = $this->analytics()->findLinkForUser($this->currentUser(), $this->linkId);

        abort_unless($resolvedLink instanceof ShareTrackingLinkData, 404);

        return $resolvedLink;
    }

    /**
     * @return array{
     *     outbound_shares: int,
     *     visits: int,
     *     unique_visitors: int,
     *     outcomes: int,
     *     signups: int,
     *     event_registrations: int,
     *     event_checkins: int,
     *     event_submissions: int
     * }
     */
    #[Computed]
    public function summary(): array
    {
        return $this->analytics()->summaryForLink($this->linkData());
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
        return $this->analytics()->providerBreakdownForLink($this->linkData());
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function shareLinks(): array
    {
        return $this->shareService()->redirectLinks(
            $this->linkData()->destination_url,
            trim(($this->linkData()->title_snapshot ?: config('app.name')).' - '.config('app.name')),
            $this->linkData()->title_snapshot,
        );
    }

    /**
     * @return Collection<int, array{
     *     date: string,
     *     visits: int,
     *     outcomes: int,
     *     signups: int,
     *     event_registrations: int,
     *     event_checkins: int,
     *     event_submissions: int
     * }>
     */
    #[Computed]
    public function dailyPerformance(): Collection
    {
        return $this->analytics()->dailyPerformanceForLink($this->linkData(), 14);
    }

    /**
     * @return Collection<int, array{outcome_type: string, label: string, count: int}>
     */
    #[Computed]
    public function outcomeBreakdown(): Collection
    {
        return $this->analytics()->outcomeBreakdownForLink($this->linkData());
    }

    /**
     * @return Collection<int, ShareTrackingVisitData>
     */
    #[Computed]
    public function recentVisits(): Collection
    {
        return $this->analytics()->recentVisitsForLink($this->linkData(), 20);
    }

    /**
     * @return Collection<int, ShareTrackingOutcomeData>
     */
    #[Computed]
    public function recentOutcomes(): Collection
    {
        return $this->analytics()->recentOutcomesForLink($this->linkData(), 20);
    }

    /**
     * @return array{
     *     first_seen_at: CarbonInterface|null,
     *     last_visit_at: CarbonInterface|null,
     *     last_outcome_at: CarbonInterface|null,
     *     latest_activity_at: CarbonInterface|null
     * }
     */
    #[Computed]
    public function activityWindow(): array
    {
        return $this->analytics()->activityWindowForLink($this->linkData());
    }

    protected function analytics(): ShareTrackingAnalyticsService
    {
        return app(ShareTrackingAnalyticsService::class);
    }

    protected function shareService(): ShareTrackingService
    {
        return app(ShareTrackingService::class);
    }

    protected function currentUser(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    public function render(): View
    {
        return view('livewire.pages.dashboard.dawah-impact-link-show');
    }
}
