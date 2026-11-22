<?php

namespace App\Features\Institutions\EntryPoints\Livewire\Pages;

use AIArmada\Addressing\Models\Address;
use AIArmada\Addressing\Models\State;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\CommerceSupport\Support\UserDateTimeFormatter;
use AIArmada\Membership\Enums\MemberRole;
use App\Features\Engagement\Api\EventEngagement;
use App\Features\Engagement\Api\EventEngagementToggles;
use App\Features\Engagement\Api\SubjectFollowCommands;
use App\Features\Events\Api\Data\PublicScheduleRenderedCard;
use App\Features\Events\Api\EventSchedulePageData;
use App\Features\Institutions\Api\Enums\InstitutionStatus;
use App\Features\Institutions\Models\Institution;
use App\Models\User;
use App\Platform\Location\Ui\Concerns\JoinsHierarchyAreaName;
use App\Shared\Auth\IntendedRedirect;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Show extends Component
{
    use JoinsHierarchyAreaName;

    public Institution $institution;

    public int $upcomingPerPage = 6;

    public int $pastPerPage = 6;

    public bool $isFollowing = false;

    public string $upcomingDateFilter = 'all';

    public string $customStartDate = '';

    public string $customEndDate = '';

    public bool $showCustomDateRange = false;

    /**
     * @var array{upcoming: list<PublicScheduleRenderedCard>, past: list<PublicScheduleRenderedCard>, upcoming_total: int, past_total: int, upcoming_overall_total: int, past_overall_total: int}|null
     */
    private ?array $schedulePageData = null;

    public function boot(): void
    {
        OwnerContext::setForRequest(null);
    }

    public function mount(Institution $institution): void
    {
        $canBypassVisibility = auth()->user()?->hasAnyRole(['super_admin', 'moderator']) ?? false;

        abort_unless(
            in_array($institution->status, InstitutionStatus::publiclyVisible(), true) || $canBypassVisibility,
            404,
        );

        $this->institution = $institution;
        $this->loadInstitutionRelations();

        $this->isFollowing = auth()->user()?->isFollowing($institution) ?? false;
    }

    protected function eventSaveReturnUrl(): string
    {
        return route('institutions.show', $this->institution, absolute: false);
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
                IntendedRedirect::loginUrl(route('institutions.show', $this->institution, absolute: false)),
                navigate: true,
            );

            return;
        }

        if ($this->isFollowing) {
            app(SubjectFollowCommands::class)->unfollow($this->institution->getMorphClass(), (string) $this->institution->getKey(), (string) $user->getKey());
            $this->isFollowing = false;

            return;
        }

        app(SubjectFollowCommands::class)->follow(
            $this->institution->getMorphClass(),
            (string) $this->institution->getKey(),
            (string) $user->getKey(),
            ['institution_id' => $this->institution->getKey()],
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
        return collect($this->schedulePageData()['upcoming']);
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
        return collect($this->schedulePageData()['past']);
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

    public function getHasAdminOrOwnerMemberProperty(): bool
    {
        return OwnerContext::withOwner(null, fn (): bool => $this->institution->members()
            ->wherePivotIn('role', [
                MemberRole::Admin->value,
                MemberRole::Owner->value,
            ])
            ->exists());
    }

    /**
     * @return array{upcoming: list<PublicScheduleRenderedCard>, past: list<PublicScheduleRenderedCard>, upcoming_total: int, past_total: int, upcoming_overall_total: int, past_overall_total: int}
     */
    private function schedulePageData(): array
    {
        if ($this->schedulePageData !== null) {
            return $this->schedulePageData;
        }

        $range = $this->upcomingDateRange();

        /** @var array{upcoming: list<PublicScheduleRenderedCard>, past: list<PublicScheduleRenderedCard>, upcoming_total: int, past_total: int, upcoming_overall_total: int, past_overall_total: int} $data */
        $data = app(EventSchedulePageData::class)->forInstitution(
            (string) $this->institution->getKey(),
            $this->upcomingPerPage,
            $this->pastPerPage,
            $range['from'] ?? null,
            $range['to'] ?? null,
            savedEventIds: $this->savedEventIds(),
            goingEventIds: $this->goingEventIds(),
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
        $this->loadInstitutionRelations();

        return view('livewire.pages.institutions.show');
    }

    private function loadInstitutionRelations(): void
    {
        OwnerContext::withOwner(null, function (): void {
            $this->institution->loadMissing([
                'media',
                'addresses' => $this->joinAddressStateName(...),
                'addresses.city',
                'addresses.areaAssignments' => $this->joinAreaName(...),
                'contactMethods',
                'publicSocialProfiles',
                'donationChannels.media',
                'persons',
                'persons.media',
                'persons.titleAssignments.title.category',
                'languages',
            ]);
        });
    }

    /**
     * @template TDeclaringModel of Model
     *
     * @param  MorphToMany<Address, TDeclaringModel, MorphPivot, 'pivot'>  $relation
     * @return MorphToMany<Address, TDeclaringModel, MorphPivot, 'pivot'>
     */
    private function joinAddressStateName(MorphToMany $relation): MorphToMany
    {
        $addressesTable = (new Address)->getTable();
        $statesTable = (new State)->getTable();

        return $relation
            ->addSelect("{$addressesTable}.*")
            ->leftJoin($statesTable, "{$addressesTable}.state_id", '=', "{$statesTable}.id")
            ->addSelect("{$statesTable}.name as hierarchy_state_name");
    }
}
