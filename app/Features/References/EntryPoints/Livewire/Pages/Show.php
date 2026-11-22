<?php

namespace App\Features\References\EntryPoints\Livewire\Pages;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Features\Events\Api\Data\PublicScheduleRenderedCard;
use App\Features\Engagement\Api\EventEngagement;
use App\Features\Engagement\Api\EventEngagementToggles;
use App\Features\Engagement\Api\SubjectFollowCommands;
use App\Features\Events\Api\Orm\EventSchedulePageData;
use App\Features\References\Api\ReferenceQueries;
use App\Features\References\Models\Reference;
use App\Models\User;
use App\Platform\Time\UserDateTimeFormatter;
use App\Shared\Auth\IntendedRedirect;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Show extends Component
{
    public Reference $reference;

    public int $upcomingPerPage = 6;

    public int $pastPerPage = 6;

    public bool $isFollowing = false;

    public string $upcomingDateFilter = 'all';

    public string $customStartDate = '';

    public string $customEndDate = '';

    public bool $showCustomDateRange = false;

    /**
     * @var array{upcoming: Collection<int, PublicScheduleRenderedCard>, past: Collection<int, PublicScheduleRenderedCard>, upcoming_total: int, past_total: int, upcoming_overall_total: int, past_overall_total: int}|null
     */
    private ?array $schedulePageData = null;

    public function boot(): void
    {
        OwnerContext::setForRequest(null);
    }

    public function mount(Reference $reference): void
    {
        $canBypassVisibility = auth()->user()?->hasAnyRole(['super_admin', 'moderator']) ?? false;

        abort_unless(
            $reference->isPubliclyVisible() || $canBypassVisibility,
            404,
        );

        $this->reference = $reference;
        $this->loadReferenceRelations();
        $this->isFollowing = auth()->user()?->isFollowing($reference) ?? false;
    }

    protected function eventSaveReturnUrl(): string
    {
        return route('references.show', $this->reference, absolute: false);
    }

    protected function eventGoingReturnUrl(): string
    {
        return $this->eventSaveReturnUrl();
    }

    public function toggleFollow(): void
    {
        $user = auth()->user();

        if (! $user) {
            $this->redirect(
                IntendedRedirect::loginUrl(route('references.show', $this->reference, absolute: false)),
                navigate: true,
            );

            return;
        }

        if ($this->isFollowing) {
            app(SubjectFollowCommands::class)->unfollow($this->reference->getMorphClass(), (string) $this->reference->getKey(), (string) $user->getKey());
            $this->isFollowing = false;

            return;
        }

        app(SubjectFollowCommands::class)->follow(
            $this->reference->getMorphClass(),
            (string) $this->reference->getKey(),
            (string) $user->getKey(),
            ['reference_id' => $this->reference->getKey()],
        );
        $this->isFollowing = true;
    }

    public function toggleGoing(string $eventId): void
    {
        $loginUrl = app(EventEngagementToggles::class)->toggleGoing($eventId, $this->eventGoingReturnUrl());

        if ($loginUrl !== null) {
            $this->redirect($loginUrl, navigate: true);
        }
    }

    public function toggleSave(string $eventId): void
    {
        $loginUrl = app(EventEngagementToggles::class)->toggleSave($eventId, $this->eventSaveReturnUrl());

        if ($loginUrl !== null) {
            $this->redirect($loginUrl, navigate: true);
        }
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function goingEventIds(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        return app(EventEngagement::class)->goingEventIds((string) $user->getKey());
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function savedEventIds(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        return app(EventEngagement::class)->savedEventIds((string) $user->getKey());
    }

    public function filterUpcomingEvents(): void
    {
        $this->schedulePageData = null;
    }

    public function updatedUpcomingDateFilter(): void
    {
        if ($this->upcomingDateFilter === 'custom') {
            $this->resetValidation('customDateRange');

            return;
        }

        $this->filterUpcomingEvents();
    }

    public function applyCustomDateRange(): void
    {
        $from = UserDateTimeFormatter::parseUserDateToUtc($this->customStartDate);
        $to = UserDateTimeFormatter::parseUserDateToUtc($this->customEndDate);

        if (! $from instanceof CarbonInterface || ! $to instanceof CarbonInterface || $from->greaterThan($to)) {
            $this->addError('customDateRange', __('Sila pilih julat tarikh yang sah.'));

            return;
        }

        $this->resetValidation('customDateRange');
        $this->upcomingDateFilter = 'custom';
        $this->showCustomDateRange = false;
        $this->filterUpcomingEvents();
    }

    public function clearUpcomingDateFilter(): void
    {
        $this->upcomingDateFilter = 'all';
        $this->customStartDate = '';
        $this->customEndDate = '';
        $this->showCustomDateRange = false;
        $this->resetValidation('customDateRange');
        $this->filterUpcomingEvents();
    }

    public function loadMoreUpcoming(): void
    {
        $this->upcomingPerPage += 6;
        $this->schedulePageData = null;
    }

    public function loadMorePast(): void
    {
        $this->pastPerPage += 6;
        $this->schedulePageData = null;
    }

    /**
     * @return Collection<int, PublicScheduleRenderedCard>
     */
    public function getUpcomingEventsProperty(): Collection
    {
        return $this->schedulePageData()['upcoming'];
    }

    public function getUpcomingTotalProperty(): int
    {
        return $this->schedulePageData()['upcoming_total'];
    }

    /**
     * @return Collection<int, PublicScheduleRenderedCard>
     */
    public function getPastEventsProperty(): Collection
    {
        return $this->schedulePageData()['past'];
    }

    public function getPastTotalProperty(): int
    {
        return $this->schedulePageData()['past_total'];
    }

    public function getUpcomingOverallTotalProperty(): int
    {
        return $this->schedulePageData()['upcoming_overall_total'];
    }

    public function getPastOverallTotalProperty(): int
    {
        return $this->schedulePageData()['past_overall_total'];
    }

    /**
     * @return array{upcoming: Collection<int, PublicScheduleRenderedCard>, past: Collection<int, PublicScheduleRenderedCard>, upcoming_total: int, past_total: int, upcoming_overall_total: int, past_overall_total: int}
     */
    private function schedulePageData(): array
    {
        if ($this->schedulePageData !== null) {
            return $this->schedulePageData;
        }

        $range = $this->upcomingDateRange();

        /** @var array{upcoming: Collection<int, PublicScheduleRenderedCard>, past: Collection<int, PublicScheduleRenderedCard>, upcoming_total: int, past_total: int, upcoming_overall_total: int, past_overall_total: int} $data */
        $referenceIds = app(ReferenceQueries::class)->defaultEventReferenceIds((string) $this->reference->getKey());
        $data = app(EventSchedulePageData::class)->forReferenceFamily(
            referenceIds: $referenceIds,
            upcomingLimit: $this->upcomingPerPage,
            pastLimit: $this->pastPerPage,
            rangeFrom: $range['from'] ?? null,
            rangeTo: $range['to'] ?? null,
            savedEventIds: $this->savedEventIds,
            goingEventIds: $this->goingEventIds,
        );

        return $this->schedulePageData = $data;
    }

    /**
     * @return array{from: CarbonInterface, to: CarbonInterface}|null
     */
    private function upcomingDateRange(): ?array
    {
        if ($this->upcomingDateFilter === 'custom') {
            $from = UserDateTimeFormatter::parseUserDateToUtc($this->customStartDate);
            $to = UserDateTimeFormatter::parseUserDateToUtc($this->customEndDate)?->addDay();

            if (! $from instanceof CarbonInterface || $to === null || $from->greaterThanOrEqualTo($to)) {
                return null;
            }

            return [
                'from' => $from,
                'to' => $to,
            ];
        }

        $today = UserDateTimeFormatter::userNow()->startOfDay();

        [$from, $to] = match ($this->upcomingDateFilter) {
            'today' => [$today, $today->copy()->addDay()],
            'tomorrow' => [$today->copy()->addDay(), $today->copy()->addDays(2)],
            'this_week' => [$today, $today->copy()->startOfWeek()->addWeek()],
            'this_weekend' => $this->weekendDateRange($today),
            'this_month' => [$today->copy()->startOfMonth(), $today->copy()->startOfMonth()->addMonth()],
            'next_week' => [
                $today->copy()->startOfWeek()->addWeek(),
                $today->copy()->startOfWeek()->addWeeks(2),
            ],
            'next_month' => [
                $today->copy()->startOfMonth()->addMonth(),
                $today->copy()->startOfMonth()->addMonths(2),
            ],
            default => [null, null],
        };

        if ($from === null || $to === null) {
            return null;
        }

        return [
            'from' => $from->utc(),
            'to' => $to->utc(),
        ];
    }

    /**
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    private function weekendDateRange(CarbonInterface $today): array
    {
        // On Sunday the weekend began yesterday: anchor on Saturday so the
        // window stays Sat + Sun instead of drifting into Monday.
        $weekendStart = $today->isSunday()
            ? $today->copy()->subDay()
            : ($today->isSaturday() ? $today->copy() : $today->copy()->next(Carbon::SATURDAY));

        return [$weekendStart, $weekendStart->copy()->addDays(2)->startOfDay()];
    }

    public function render(): View
    {
        $this->loadReferenceRelations();

        return view('livewire.pages.references.show');
    }

    private function loadReferenceRelations(): void
    {
        OwnerContext::withOwner(null, function (): void {
            $this->reference->load([
                'media',
                'socialProfiles',
                'authors.titleAssignments.title.category',
                'parentReference.authors.titleAssignments.title.category',
                'parentReference.parentReference.authors.titleAssignments.title.category',
                'parentReference' => fn ($query) => $query->active()->with(['parentReference' => fn ($parent) => $parent->active()]),
                'childReferences' => fn ($query) => $query->active()->with(['parentReference.parentReference', 'childReferences' => fn ($parts) => $parts->active()->with('parentReference.parentReference')]),
            ]);
        });
    }
}
