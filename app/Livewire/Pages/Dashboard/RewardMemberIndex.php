<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Dashboard;

use AIArmada\Rewards\Actions\LeaveRewardProgramAction;
use AIArmada\Rewards\Actions\OpenRewardAppealAction;
use AIArmada\Rewards\Actions\ReacceptRewardTermsAction;
use AIArmada\Rewards\Actions\RedeemRewardBenefitAction;
use AIArmada\Rewards\Actions\RegisterRewardPayeeAction;
use AIArmada\Rewards\Enums\RewardBenefitItemStatus;
use AIArmada\Rewards\Enums\RewardMilestoneStatus;
use AIArmada\Rewards\Enums\RewardProgramStatus;
use AIArmada\Rewards\Models\RewardAppeal;
use AIArmada\Rewards\Models\RewardAward;
use AIArmada\Rewards\Models\RewardBenefitGrant;
use AIArmada\Rewards\Models\RewardBenefitItem;
use AIArmada\Rewards\Models\RewardEnrollment;
use AIArmada\Rewards\Models\RewardEnrollmentAcceptance;
use AIArmada\Rewards\Models\RewardMilestoneCycle;
use AIArmada\Rewards\Models\RewardPayee;
use AIArmada\Rewards\Models\RewardPayoutRequest;
use AIArmada\Rewards\Models\RewardPointLot;
use AIArmada\Rewards\Models\RewardProgram;
use AIArmada\Rewards\Models\RewardProgramVersion;
use AIArmada\Rewards\Support\RewardBenefitStockLedger;
use AIArmada\Rewards\Support\RewardPointLedger;
use App\Actions\Rewards\EnrollRewardProgramMemberAction;
use App\Actions\Rewards\EvaluatePreclaimShareOutcomesAction;
use App\Models\User;
use App\Support\Rewards\PublishedRewardProgramVersionResolver;
use App\Support\Rewards\RewardMemberPresenter;
use App\Support\Share\ShareMetricReader;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class RewardMemberIndex extends Component
{
    use WithPagination;

    public const array TABS = [
        'overview', 'programs', 'appeals', 'statements', 'payee', 'impact', 'catalog', 'grants',
    ];

    public string $tab = 'overview';

    public string $notice = '';

    public string $leaveReason = '';

    public string $appealAwardId = '';

    public string $appealReason = '';

    public string $payeeMethod = 'manual';

    public string $payeeRef = '';

    public string $payeeDisplay = '';

    public function mount(): void
    {
        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'overview';
        }
    }

    public function showTab(string $tab): void
    {
        $this->tab = in_array($tab, self::TABS, true) ? $tab : 'overview';
        $this->resetPage();
    }

    public function enroll(string $programId): void
    {
        $user = $this->currentUser();
        $program = RewardProgram::query()->whereKey($programId)->firstOrFail();

        try {
            $enrollment = EnrollRewardProgramMemberAction::run($program, $user);
        } catch (InvalidArgumentException $exception) {
            $this->notice = $exception->getMessage();

            return;
        }

        if ($enrollment === null) {
            $this->notice = 'This program has no published terms yet, so enrollment is not open.';

            return;
        }

        $this->notice = 'Enrolled. Your awards under this program now accrue to you.';
    }

    public function leave(string $programId): void
    {
        $user = $this->currentUser();
        $program = RewardProgram::query()->whereKey($programId)->firstOrFail();
        $this->authorize('leave', $program);

        $enrollment = RewardEnrollment::query()
            ->where('program_id', $program->getKey())
            ->where('member_type', $user->getMorphClass())
            ->where('member_id', $user->getKey())
            ->firstOrFail();

        try {
            LeaveRewardProgramAction::run($enrollment, $this->leaveReason !== '' ? $this->leaveReason : null);
        } catch (InvalidArgumentException $exception) {
            $this->notice = $exception->getMessage();

            return;
        }

        $this->leaveReason = '';
        $this->notice = 'You left the program. Earned obligations are retained; future attribution stops.';
    }

    public function reaccept(string $programId): void
    {
        $user = $this->currentUser();
        $program = RewardProgram::query()->whereKey($programId)->firstOrFail();
        $this->authorize('leave', $program);

        $enrollment = RewardEnrollment::query()
            ->where('program_id', $program->getKey())
            ->where('member_type', $user->getMorphClass())
            ->where('member_id', $user->getKey())
            ->firstOrFail();

        $version = $this->applicableVersion($program);

        if (! $version instanceof RewardProgramVersion) {
            $this->notice = 'This program has no published terms yet.';

            return;
        }

        try {
            ReacceptRewardTermsAction::run($enrollment, $version, 'member-portal');
        } catch (InvalidArgumentException $exception) {
            $this->notice = $exception->getMessage();

            return;
        }

        EvaluatePreclaimShareOutcomesAction::run($program, $user, $version);

        $this->notice = 'Terms reaccepted. Accrued obligations keep their frozen terms.';
    }

    public function fileAppeal(): void
    {
        $user = $this->currentUser();

        if ($this->appealAwardId === '' || mb_trim($this->appealReason) === '') {
            $this->notice = 'Choose an award and explain what should be reviewed.';

            return;
        }

        $award = RewardAward::query()
            ->where('recipient_type', $user->getMorphClass())
            ->where('recipient_id', $user->getKey())
            ->whereKey($this->appealAwardId)
            ->firstOrFail();
        $this->authorize('openAppeal', $award);

        try {
            OpenRewardAppealAction::run($award, 'user', (string) $user->getKey(), 'member', mb_trim($this->appealReason));
        } catch (InvalidArgumentException $exception) {
            $this->notice = $exception->getMessage();

            return;
        }

        $this->appealAwardId = '';
        $this->appealReason = '';
        $this->notice = 'Appeal opened. A steward will review it and record the decision here.';
    }

    public function registerPayee(): void
    {
        $user = $this->currentUser();
        $this->authorize('register', RewardPayee::class);

        try {
            RegisterRewardPayeeAction::run(
                $user->getMorphClass(),
                (string) $user->getKey(),
                $this->payeeMethod,
                $this->payeeRef,
                $this->payeeDisplay,
                'user',
                (string) $user->getKey(),
                'member',
            );
        } catch (InvalidArgumentException $exception) {
            $this->notice = $exception->getMessage();

            return;
        }

        $this->payeeRef = '';
        $this->payeeDisplay = '';
        $this->notice = 'Destination submitted. A staff member will verify it before any payout.';
    }

    public function redeem(string $itemId): void
    {
        $user = $this->currentUser();

        $item = RewardBenefitItem::query()->whereKey($itemId)->firstOrFail();
        $this->authorize('view', $item);

        $program = RewardProgram::query()->whereKey((string) $item->program_id)->firstOrFail();

        try {
            RedeemRewardBenefitAction::run(
                $user->getMorphClass(), (string) $user->getKey(), (string) $program->family_key,
                $item, 1, 'web-'.((string) Str::uuid()), 'manual',
                'user', (string) $user->getKey(), 'member',
            );
        } catch (InvalidArgumentException $exception) {
            $this->notice = $exception->getMessage();

            return;
        }

        $this->notice = 'Redemption reserved. The team will fulfill it shortly.';
        $this->showTab('grants');
    }

    public function render(): View
    {
        $user = $this->currentUser();
        $asOf = CarbonImmutable::now('UTC');

        // Only the visible tab queries: the render sweep budgets every
        // page, and idle tabs must cost nothing.
        $awards = null;
        $histories = [];
        $balances = [];
        $programs = [];
        $milestones = [];
        $appeals = [];
        $appealsPager = null;
        $appealableAwards = [];
        $statements = [];
        $statementsPager = null;
        $payees = [];
        $impact = ['status' => 'unavailable', 'totals' => []];
        $catalog = null;
        $grants = null;

        if ($this->tab === 'overview') {
            $awards = RewardAward::query()
                ->where('recipient_type', $user->getMorphClass())
                ->where('recipient_id', $user->getKey())
                ->orderByDesc('id')
                ->paginate(15, pageName: 'awards_page');

            $families = RewardProgram::query()
                ->whereIn('id', (clone $awards->getCollection())->map(static fn (RewardAward $award): string => (string) $award->program_id)->unique()->all())
                ->get()
                ->keyBy(static fn (RewardProgram $program): string => (string) $program->getKey());

            $histories = $awards->getCollection()->map(
                static fn (RewardAward $award): array => RewardMemberPresenter::awardSummary($award, $families->get((string) $award->program_id)),
            );

            // Balances derive from the member's point lots, never from the
            // visible award page: paging through history must not move money.
            $balances = $this->balancesFor($user, $asOf);
            $milestones = $this->milestoneProgressFor($user);
        } elseif ($this->tab === 'programs') {
            $programs = $this->programsFor($user);
        } elseif ($this->tab === 'appeals') {
            [$appeals, $appealsPager] = $this->appealsFor($user);
            $appealableAwards = $this->appealableAwardsFor($user);
        } elseif ($this->tab === 'statements') {
            [$statements, $statementsPager] = $this->statementsFor($user);
        } elseif ($this->tab === 'payee') {
            $payees = $this->payeesFor($user);
        } elseif ($this->tab === 'impact') {
            $impact = $this->impactFor($user);
        } elseif ($this->tab === 'catalog') {
            $catalog = RewardBenefitItem::query()
                ->where('status', RewardBenefitItemStatus::Active->value)
                ->orderBy('title')
                ->paginate(15, pageName: 'catalog_page');
        } elseif ($this->tab === 'grants') {
            $grants = RewardBenefitGrant::query()
                ->where('recipient_type', $user->getMorphClass())
                ->where('recipient_id', $user->getKey())
                ->orderByDesc('reserved_at')
                ->paginate(15, pageName: 'grants_page');
        }

        return view('livewire.pages.dashboard.reward-member-index', [
            'awards' => $awards,
            'histories' => $histories,
            'balances' => $balances,
            'milestones' => $milestones,
            'programs' => $programs,
            'appeals' => $appeals,
            'appealsPager' => $appealsPager,
            'appealableAwards' => $appealableAwards,
            'statements' => $statements,
            'statementsPager' => $statementsPager,
            'payees' => $payees,
            'impact' => $impact,
            'catalog' => $catalog,
            'stockOf' => static fn (RewardBenefitItem $item): array => RewardBenefitStockLedger::balances($item),
            'grants' => $grants,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function balancesFor(User $user, CarbonImmutable $asOf): array
    {
        $lots = RewardPointLot::query()
            ->where('recipient_type', $user->getMorphClass())
            ->where('recipient_id', $user->getKey())
            ->select('family_key', 'unit')
            ->distinct()
            ->get();

        $programs = RewardProgram::query()
            ->whereIn('family_key', $lots->map(static fn ($lot): string => (string) $lot->family_key)->unique()->all())
            ->get()
            ->keyBy(static fn (RewardProgram $program): string => (string) $program->family_key);

        $balances = [];

        foreach ($lots as $lot) {
            $familyKey = (string) $lot->family_key;
            $unit = (string) $lot->unit;
            $buckets = RewardPointLedger::balancesFor($user->getMorphClass(), (string) $user->getKey(), $familyKey, $unit, $asOf);
            $program = $programs->get($familyKey);
            $balances[] = [
                'program' => $program instanceof RewardProgram ? (string) $program->title : $familyKey,
                'family_key' => $familyKey,
                'unit' => $unit,
                'available' => $buckets->available,
                'reserved' => $buckets->reserved,
                'spent' => $buckets->spent,
            ];
        }

        return $balances;
    }

    /**
     * @return list<array{program: string, label: string, current: int, target: int, status: string}>
     */
    private function milestoneProgressFor(User $user): array
    {
        $cycles = RewardMilestoneCycle::query()
            ->where('recipient_type', $user->getMorphClass())
            ->where('recipient_id', $user->getKey())
            ->whereIn('status', [RewardMilestoneStatus::Open->value, RewardMilestoneStatus::Achieved->value])
            ->orderBy('window_start')
            ->get();

        if ($cycles->isEmpty()) {
            return [];
        }

        $programs = RewardProgram::query()
            ->whereIn('id', $cycles->map(static fn (RewardMilestoneCycle $cycle): string => (string) $cycle->program_id)->unique()->all())
            ->get()
            ->keyBy(static fn (RewardProgram $program): string => (string) $program->getKey());

        $progress = [];

        foreach ($cycles as $cycle) {
            $program = $programs->get((string) $cycle->program_id);

            if (! $program instanceof RewardProgram || $user->cannot('view', $program)) {
                continue;
            }

            $version = $this->applicableVersion($program);
            $label = $cycle->milestone_key;

            foreach (is_array($version?->rules['milestones'] ?? null) ? $version->rules['milestones'] : [] as $definition) {
                if (is_array($definition) && ($definition['key'] ?? null) === $cycle->milestone_key) {
                    $label = is_string($definition['label'] ?? null) ? $definition['label'] : $label;

                    break;
                }
            }

            $progress[] = [
                'program' => (string) $program->title,
                'label' => $label,
                'current' => (int) $cycle->current_count,
                'target' => (int) $cycle->target_count,
                'status' => $cycle->status->value,
            ];
        }

        return $progress;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function programsFor(User $user): array
    {
        $programs = RewardProgram::query()
            ->whereIn('status', [RewardProgramStatus::Ready->value, RewardProgramStatus::Active->value])
            ->orderBy('title')
            ->get();

        $enrollments = RewardEnrollment::query()
            ->where('member_type', $user->getMorphClass())
            ->where('member_id', $user->getKey())
            ->get()
            ->keyBy(static fn (RewardEnrollment $enrollment): string => (string) $enrollment->program_id);

        $rows = [];

        foreach ($programs as $program) {
            if ($user->cannot('view', $program)) {
                continue;
            }

            $enrollment = $enrollments->get((string) $program->getKey());
            $version = $this->applicableVersion($program);

            $acceptance = $enrollment instanceof RewardEnrollment
                ? RewardEnrollmentAcceptance::query()
                    ->where('enrollment_id', $enrollment->getKey())
                    ->orderByDesc('accepted_at')
                    ->orderByDesc('seq')
                    ->first()
                : null;

            $rows[] = [
                'detail' => RewardMemberPresenter::programDetail($program, $version, $enrollment, $acceptance),
                'can_enroll' => $user->can('enroll', $program),
                'can_leave' => $user->can('leave', $program),
            ];
        }

        return $rows;
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: LengthAwarePaginator<int, RewardAppeal>}
     */
    private function appealsFor(User $user): array
    {
        $awardIds = RewardAward::query()
            ->where('recipient_type', $user->getMorphClass())
            ->where('recipient_id', $user->getKey())
            ->pluck('id');

        $pager = RewardAppeal::query()
            ->whereIn('award_id', $awardIds->all())
            ->orderByDesc('opened_at')
            ->paginate(15, pageName: 'appeals_page');

        $rows = $pager->getCollection()
            ->map(static fn (RewardAppeal $appeal): array => RewardMemberPresenter::appealSummary($appeal))
            ->all();

        return [$rows, $pager];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function appealableAwardsFor(User $user): array
    {
        return RewardAward::query()
            ->where('recipient_type', $user->getMorphClass())
            ->where('recipient_id', $user->getKey())
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(static fn (RewardAward $award): array => [
                'id' => (string) $award->getKey(),
                'label' => $award->component->value.' — '.$award->status->value,
            ])
            ->all();
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: LengthAwarePaginator<int, RewardPayoutRequest>}
     */
    private function statementsFor(User $user): array
    {
        $awardIds = RewardAward::query()
            ->where('recipient_type', $user->getMorphClass())
            ->where('recipient_id', $user->getKey())
            ->pluck('id');

        $pager = RewardPayoutRequest::query()
            ->whereIn('award_id', $awardIds->all())
            ->orderByDesc('requested_at')
            ->paginate(15, pageName: 'payouts_page');

        $rows = $pager->getCollection()
            ->map(static function (RewardPayoutRequest $payout): array {
                $snapshot = $payout->payee_snapshot;
                $confirmedAt = $payout->confirmed_at?->toIso8601String();

                return [
                    'status' => $payout->status->value,
                    'funds' => RewardMemberPresenter::payoutFundsState($payout->status->value, $confirmedAt),
                    'currency' => (string) $payout->currency,
                    'amount_minor' => (int) $payout->amount_minor,
                    'processor' => (string) $payout->processor,
                    'account_display' => $snapshot['account_display'] ?? null,
                    'requested_at' => $payout->requested_at?->toIso8601String(),
                    'confirmed_at' => $confirmedAt,
                ];
            })
            ->all();

        return [$rows, $pager];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function payeesFor(User $user): array
    {
        return RewardPayee::query()
            ->where('recipient_type', $user->getMorphClass())
            ->where('recipient_id', $user->getKey())
            ->orderByDesc('verified_at')
            ->orderByDesc('id')
            ->limit(25)
            ->get()
            ->map(static fn (RewardPayee $payee): array => RewardMemberPresenter::payeeSummary($payee))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function impactFor(User $user): array
    {
        $to = CarbonImmutable::now('UTC');
        $from = $to->subDays(30);

        try {
            return app(ShareMetricReader::class)->summarize(
                (string) $user->preferredTimezone(), $from, $to, $user,
            );
        } catch (InvalidArgumentException) {
            return ['status' => 'unavailable', 'totals' => []];
        }
    }

    private function applicableVersion(RewardProgram $program): ?RewardProgramVersion
    {
        return app(PublishedRewardProgramVersionResolver::class)->resolve($program);
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
