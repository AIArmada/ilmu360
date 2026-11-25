<?php

declare(strict_types=1);

namespace App\Features\Contributions\EntryPoints\Livewire\Pages;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Features\Contributions\Api\ContributionRequestLifecycle;
use App\Features\Contributions\Api\ContributionRequestReader;
use App\Features\Contributions\Api\Enums\ContributionSubjectType;
use App\Features\Contributions\EntryPoints\Ui\ContributionsHubPresenter;
use App\Features\Contributions\Enums\ContributionRequestStatus;
use App\Features\Contributions\Enums\ContributionRequestType;
use App\Features\Contributions\Models\ContributionRequest;
use App\Features\Events\Api\Data\EventSubmissionCard;
use App\Features\Events\Api\Data\EventSubmissionPage;
use App\Features\Events\Api\EventSubmissions;
use App\Features\Feedback\Api\AppFeedbackReader;
use App\Features\Feedback\Api\AppFeedbackSnapshot;
use App\Features\Institutions\Api\InstitutionLookup;
use App\Features\Membership\Api\MembershipApplicationDetail;
use App\Features\Membership\Api\MembershipApplicationEvidence;
use App\Features\Membership\Api\MembershipApplications;
use App\Features\Membership\Api\MemberSubjectType;
use App\Features\Persons\Api\PersonLookup;
use App\Features\References\Api\ReferenceLookup;
use App\Features\Reports\Api\ReportPage;
use App\Features\Reports\Api\ReportReader;
use App\Features\Reports\Api\ReportSnapshot;
use App\Models\User;
use App\Platform\Models\MorphModelResolver;
use App\Platform\Ui\InteractsWithToasts;
use App\Shared\Signals\SignalRecorder;
use BackedEnum;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

/**
 * @property-read Collection<int, MembershipApplicationDetail> $myMembershipClaims
 * @property-read Collection<int, MembershipApplicationDetail> $allMembershipClaims
 * @property-read array<string, mixed>|null $inspectedItem
 */
#[Layout('layouts.app')]
#[Title('My Contributions')]
class Index extends Component
{
    use InteractsWithToasts;
    use WithPagination;

    #[Url(as: 'tab', except: 'overview')]
    public string $activeTab = 'overview';

    #[Url(as: 'status', except: 'all')]
    public string $statusFilter = 'all';

    #[Url(as: 'type', except: 'all')]
    public string $subjectFilter = 'all';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'lihat', except: null)]
    public ?string $inspect = null;

    public bool $showDetailDrawer = false;

    public bool $showRecordFinder = false;

    public string $finderMode = 'update';

    public string $finderSubjectType = 'event';

    public string $finderQuery = '';

    public string $claimSubjectType = 'institution';

    public string $claimSearch = '';

    public ?string $selectedClaimIdentifier = null;

    public ?string $selectedClaimLabel = null;

    public function mount(): void
    {
        abort_unless(auth()->user() instanceof User, 403);

        $this->activeTab = $this->normalizeTab($this->activeTab);
        $this->statusFilter = $this->normalizeStatusFilter($this->statusFilter);
        $this->subjectFilter = $this->normalizeSubjectFilter($this->subjectFilter);
        $this->claimSubjectType = $this->normalizeClaimSubjectType($this->claimSubjectType);

        $normalizedInspect = $this->normalizeInspectKey($this->inspect);

        if ($normalizedInspect !== null) {
            $this->inspect = $normalizedInspect;

            if ($this->inspectedItem !== null) {
                $this->showDetailDrawer = true;

                return;
            }
        }

        $this->inspect = null;
        $this->showDetailDrawer = false;
    }

    public function inspectItem(string $key, SignalRecorder $signals): void
    {
        $normalized = $this->normalizeInspectKey($key);

        if ($normalized === null) {
            return;
        }

        $this->inspect = $normalized;
        unset($this->inspectedItem);

        if ($this->inspectedItem === null) {
            $this->inspect = null;
            $this->showDetailDrawer = false;

            return;
        }

        $this->showDetailDrawer = true;

        $this->recordSignal($signals, 'contribution.hub_detail_opened', [
            'kind' => explode(':', $normalized, 2)[0],
        ]);
    }

    public function closeDetail(): void
    {
        $this->showDetailDrawer = false;
        $this->inspect = null;
        unset($this->inspectedItem);
    }

    public function updatedShowDetailDrawer(bool $value): void
    {
        if (! $value) {
            $this->inspect = null;
            unset($this->inspectedItem);
        }
    }

    public function selectTab(string $tab, SignalRecorder $signals): void
    {
        $this->activeTab = $this->normalizeTab($tab);
        $this->statusFilter = 'all';
        $this->subjectFilter = 'all';
        $this->search = '';
        $this->resetPaginationPages();

        $this->recordSignal($signals, 'contribution.hub_tab_changed', ['tab' => $this->activeTab]);
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPaginationPages();
    }

    public function updatedSubjectFilter(): void
    {
        $this->resetPaginationPages();
    }

    public function updatedSearch(): void
    {
        $this->resetPaginationPages();
    }

    public function resetFilters(): void
    {
        $this->statusFilter = 'all';
        $this->subjectFilter = 'all';
        $this->search = '';
        $this->resetPaginationPages();
    }

    public function openRecordFinder(string $mode, ?string $subjectType, SignalRecorder $signals): void
    {
        /** @var User $user */
        $user = auth()->user();

        if (! $user->canSubmitDirectoryFeedback()) {
            $this->errorToast($user->directoryFeedbackBanMessage());

            return;
        }

        $normalizedMode = in_array($mode, ['update', 'report', 'claim'], true) ? $mode : 'update';
        $allowedTypes = $this->finderSubjectTypes($normalizedMode);
        $normalizedSubjectType = is_string($subjectType) && in_array($subjectType, $allowedTypes, true)
            ? $subjectType
            : $allowedTypes[0];

        $this->finderMode = $normalizedMode;
        $this->finderSubjectType = $normalizedSubjectType;
        $this->finderQuery = '';
        $this->showRecordFinder = true;

        $this->recordSignal($signals, 'contribution.hub_record_finder_opened', [
            'mode' => $normalizedMode,
            'subject_type' => $normalizedSubjectType,
        ]);
    }

    public function setFinderSubjectType(string $subjectType): void
    {
        $allowedTypes = $this->finderSubjectTypes($this->finderMode);

        $this->finderSubjectType = in_array($subjectType, $allowedTypes, true)
            ? $subjectType
            : $allowedTypes[0];
        $this->finderQuery = '';
    }

    public function closeRecordFinder(): void
    {
        $this->showRecordFinder = false;
        $this->finderQuery = '';
    }

    public function updatedClaimSubjectType(): void
    {
        $this->claimSubjectType = $this->normalizeClaimSubjectType($this->claimSubjectType);
        $this->claimSearch = '';
        $this->selectedClaimIdentifier = null;
        $this->selectedClaimLabel = null;
        $this->resetValidation('selectedClaimIdentifier');
    }

    public function selectClaimRecord(string $identifier, string $label): void
    {
        $this->selectedClaimIdentifier = $identifier;
        $this->selectedClaimLabel = $label;
        $this->resetValidation('selectedClaimIdentifier');

        $this->recordSignal(app(SignalRecorder::class), 'contribution.hub_record_selected', [
            'mode' => 'claim',
            'subject_type' => $this->claimSubjectType,
        ]);
    }

    public function clearClaimSelection(): void
    {
        $this->selectedClaimIdentifier = null;
        $this->selectedClaimLabel = null;
    }

    public function startMembershipClaim(): void
    {
        /** @var User $user */
        $user = auth()->user();

        if (! $user->canSubmitDirectoryFeedback()) {
            $this->errorToast($user->directoryFeedbackBanMessage());

            return;
        }

        $subjectType = MemberSubjectType::tryFrom($this->claimSubjectType);
        $identifier = $this->selectedClaimIdentifier;

        if (! $subjectType instanceof MemberSubjectType
            || ! $subjectType->supportsMembershipApplication()
            || $identifier === null
            || trim($identifier) === '') {
            $this->addError('selectedClaimIdentifier', __('Select a record before continuing.'));

            return;
        }

        $this->redirectRoute('membership-applications.create', [
            'subjectType' => $subjectType->publicRouteSegment(),
            'subjectId' => $identifier,
        ], navigate: true);
    }

    public function cancelContributionRequest(
        string $requestId,
        ContributionRequestLifecycle $lifecycle,
        ContributionRequestReader $reader,
        SignalRecorder $signals,
    ): void {
        /** @var User $user */
        $user = auth()->user();

        $ownedRequestId = $reader->findRequestIdForProposer((string) $user->getKey(), $requestId);

        abort_if($ownedRequestId === null, 404);

        $request = OwnerContext::withOwner(
            null,
            fn (): ?ContributionRequest => ContributionRequest::query()->find($ownedRequestId),
        );

        if (! $request instanceof ContributionRequest) {
            abort(404);
        }

        $lifecycle->cancel((string) $request->getKey(), (string) $user->getKey());

        unset($this->directorySubmissions, $this->updateRequests, $this->summaryMetrics, $this->recentActivity, $this->inspectedItem);

        $this->recordSignal($signals, 'contribution.request_cancelled', [
            'request_type' => $this->scalarValue($request->type),
            'subject_type' => $this->scalarValue($request->subject_type),
        ]);

        $this->successToast(__('Contribution request cancelled.'));
    }

    public function cancelMembershipApplication(
        string $applicationId,
        MembershipApplications $membershipApplications,
        SignalRecorder $signals,
    ): void {
        /** @var User $user */
        $user = auth()->user();

        try {
            $claim = OwnerContext::withOwner(
                null,
                fn (): ?MembershipApplicationDetail => $membershipApplications->cancelForApplicant(
                    (string) $user->getKey(),
                    $applicationId,
                ),
            );
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'Only pending membership applications can be cancelled.') {
                $this->errorToast(__('Only pending claims can be cancelled.'));

                return;
            }

            throw $exception;
        }

        if (! $claim instanceof MembershipApplicationDetail) {
            abort(404);
        }

        unset($this->myMembershipClaims, $this->allMembershipClaims, $this->summaryMetrics, $this->recentActivity, $this->inspectedItem);

        $this->recordSignal($signals, 'contribution.membership_claim_cancelled', [
            'subject_type' => $claim->application->subjectType->value,
        ]);

        $this->successToast(__('Membership claim cancelled.'));
    }

    /**
     * @return array{
     *     is_feedback_blocked: bool,
     *     feedback_ban_message: string,
     *     has_any_workspace_access: bool,
     *     manages_institution: bool
     * }
     */
    #[Computed]
    public function viewerContext(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $accountId = (string) $user->getKey();

        return [
            'is_feedback_blocked' => ! $user->canSubmitDirectoryFeedback(),
            'feedback_ban_message' => $user->directoryFeedbackBanMessage(),
            'has_any_workspace_access' => $user->hasAhliPanelAccess(),
            'manages_institution' => app(InstitutionLookup::class)->institutionsForMember($accountId) !== [],
        ];
    }

    /**
     * @return array{
     *     totals: array{total: int, review: int, approved: int, attention: int},
     *     tabs: array{events: int, directory: int, updates: int, reports: int, membership: int, feedback: int}
     * }
     */
    #[Computed]
    public function summaryMetrics(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $accountId = (string) $user->getKey();

        $requestTotals = ['directory' => 0, 'updates' => 0, 'pending' => 0, 'approved' => 0, 'attention' => 0];

        $rows = OwnerContext::withOwner(null, fn (): Collection => ContributionRequest::query()
            ->where('proposer_id', $accountId)
            ->select(['type', 'status'])
            ->selectRaw('count(*) as aggregate')
            ->groupBy('type', 'status')
            ->get());

        foreach ($rows as $row) {
            $type = $row->getAttribute('type');
            $type = $type instanceof ContributionRequestType ? $type->value : (string) $type;
            $status = $row->getAttribute('status');
            $status = $status instanceof ContributionRequestStatus ? $status->value : (string) $status;
            $count = (int) $row->getAttribute('aggregate');

            if ($type === ContributionRequestType::Create->value) {
                $requestTotals['directory'] += $count;
            }

            if ($type === ContributionRequestType::Update->value) {
                $requestTotals['updates'] += $count;
            }

            if ($status === ContributionRequestStatus::Pending->value) {
                $requestTotals['pending'] += $count;
            } elseif ($status === ContributionRequestStatus::Approved->value) {
                $requestTotals['approved'] += $count;
            } else {
                $requestTotals['attention'] += $count;
            }
        }

        $eventCounts = OwnerContext::withOwner(
            null,
            fn (): array => app(EventSubmissions::class)->summaryCountsForSubmitter($accountId),
        );

        $reportCounts = OwnerContext::withOwner(
            null,
            fn (): array => app(ReportReader::class)->countsByStatusForReporter($accountId),
        );

        $claimCounts = ['pending' => 0, 'approved' => 0, 'attention' => 0];

        foreach ($this->allMembershipClaims as $claim) {
            $status = $claim->application->status;

            if ($status === 'pending') {
                $claimCounts['pending']++;
            } elseif ($status === 'approved') {
                $claimCounts['approved']++;
            } else {
                $claimCounts['attention']++;
            }
        }

        $feedbackCounts = app(AppFeedbackReader::class)->countsByStatusForReporter($accountId);

        $feedbackTotal = array_sum($feedbackCounts);
        $feedbackReview = ($feedbackCounts['new'] ?? 0) + ($feedbackCounts['triaged'] ?? 0);
        $feedbackApproved = $feedbackCounts['resolved'] ?? 0;
        $feedbackAttention = $feedbackCounts['declined'] ?? 0;

        $reportTotal = array_sum($reportCounts);
        $reportReview = ($reportCounts['open'] ?? 0) + ($reportCounts['triaged'] ?? 0);
        $reportResolved = $reportCounts['resolved'] ?? 0;
        $reportAttention = ($reportCounts['dismissed'] ?? 0);

        $claimsTotal = count($this->allMembershipClaims->all());

        return [
            'totals' => [
                'total' => $requestTotals['directory']
                    + $requestTotals['updates']
                    + $eventCounts['total']
                    + $reportTotal
                    + $claimsTotal
                    + $feedbackTotal,
                'review' => $requestTotals['pending']
                    + $eventCounts['pending']
                    + $reportReview
                    + $claimCounts['pending']
                    + $feedbackReview,
                'approved' => $requestTotals['approved']
                    + $eventCounts['approved']
                    + $reportResolved
                    + $claimCounts['approved']
                    + $feedbackApproved,
                'attention' => $requestTotals['attention']
                    + $eventCounts['attention']
                    + $reportAttention
                    + $claimCounts['attention']
                    + $feedbackAttention,
            ],
            'tabs' => [
                'events' => $eventCounts['total'],
                'directory' => $requestTotals['directory'],
                'updates' => $requestTotals['updates'],
                'reports' => $reportTotal,
                'membership' => $claimsTotal,
                'feedback' => $feedbackTotal,
            ],
        ];
    }

    /**
     * Latest activity across all six contribution families.
     *
     * @return list<array{
     *     key: string,
     *     inspect_key: string,
     *     tab: string,
     *     family: string,
     *     title: string,
     *     subtitle: ?string,
     *     summary: ?string,
     *     status: string,
     *     status_label: string,
     *     status_color: string,
     *     tone: string,
     *     occurred_at: ?string,
     *     proposer_note: ?string,
     *     note: ?string,
     *     url: ?string
     * }>
     */
    #[Computed]
    public function recentActivity(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $accountId = (string) $user->getKey();
        $presenter = app(ContributionsHubPresenter::class);

        $items = [];

        $requests = OwnerContext::withOwner(null, fn (): Collection => ContributionRequest::query()
            ->where('proposer_id', $accountId)
            ->with(['entity', 'reviewer', 'media'])
            ->latest('created_at')
            ->limit(5)
            ->get());

        foreach ($requests as $request) {
            $status = $this->scalarValue($request->status);
            $isUpdate = $request->type === ContributionRequestType::Update;
            $subtitle = $presenter->requestTypeLabel($request->type);

            $items[] = [
                'key' => 'request-'.$request->getKey(),
                'inspect_key' => 'request:'.$request->getKey(),
                'tab' => $isUpdate ? 'updates' : 'directory',
                'family' => $presenter->subjectLabel($request->subject_type),
                'title' => $presenter->entityTitle($request),
                'subtitle' => $subtitle,
                'summary' => $presenter->requestSummaryLine($request),
                'status' => $status,
                'status_label' => $presenter->statusLabel($status),
                'status_color' => $presenter->statusTone($status),
                'tone' => $presenter->statusTone($status),
                'occurred_at' => $request->created_at?->toIso8601String(),
                'proposer_note' => filled($request->proposer_note) ? Str::limit((string) $request->proposer_note, 110) : null,
                'note' => $request->reviewer_note,
                'url' => $presenter->requestPublicUrl($request),
            ];
        }

        $submissions = OwnerContext::withOwner(
            null,
            fn (): array => app(EventSubmissions::class)->recentForSubmitter($accountId, 5),
        );

        foreach ($submissions as $submission) {
            $status = (string) ($submission->presentation['status_value'] ?? '');
            $summary = implode(' · ', array_values(array_filter([
                $submission->presentation['date_label'] ?? null,
                $submission->presentation['time_label'] ?? null,
                $submission->details[0] ?? null,
            ])));

            $items[] = [
                'key' => 'event-'.$submission->id,
                'inspect_key' => 'event:'.$submission->id,
                'tab' => 'events',
                'family' => __('Event'),
                'title' => (string) ($submission->presentation['title'] ?? $submission->eventTitle ?? __('Event')),
                'subtitle' => $submission->presentation['date_label'] ?? null,
                'summary' => $summary !== '' ? $summary : null,
                'status' => $status,
                'status_label' => (string) ($submission->presentation['status_label'] ?? $presenter->statusLabel($status)),
                'status_color' => $presenter->statusTone($status),
                'tone' => $presenter->statusTone($status),
                'occurred_at' => $submission->createdAt,
                'proposer_note' => null,
                'note' => $submission->notes,
                'url' => $submission->presentation['url'] ?? null,
            ];
        }

        $reports = OwnerContext::withOwner(
            null,
            fn (): array => app(ReportReader::class)->forReporter($accountId, 5),
        );

        foreach ($reports as $report) {
            $summary = implode(' · ', array_values(array_filter([
                $report->categoryLabel,
                filled($report->description) ? Str::limit((string) $report->description, 90) : null,
            ])));

            $items[] = [
                'key' => 'report-'.$report->id,
                'inspect_key' => 'report:'.$report->id,
                'tab' => 'reports',
                'family' => __('Report'),
                'title' => $report->entityTitle ?? $report->entityLabel,
                'subtitle' => $report->categoryLabel,
                'summary' => $summary !== '' ? $summary : null,
                'status' => $report->status,
                'status_label' => $presenter->statusLabel($report->status),
                'status_color' => $presenter->statusTone($report->status),
                'tone' => $presenter->statusTone($report->status),
                'occurred_at' => $report->createdAt?->toIso8601String(),
                'proposer_note' => null,
                'note' => $report->resolutionNote,
                'url' => null,
            ];
        }

        foreach ($this->allMembershipClaims->take(5) as $claim) {
            $summary = implode(' · ', array_values(array_filter([
                __($claim->roleLabel),
                filled($claim->justification) ? Str::limit((string) $claim->justification, 90) : null,
                $claim->evidence !== []
                    ? (string) trans_choice(':count evidence file|:count evidence files', count($claim->evidence), ['count' => count($claim->evidence)])
                    : null,
            ])));

            $items[] = [
                'key' => 'claim-'.$claim->application->id,
                'inspect_key' => 'claim:'.$claim->application->id,
                'tab' => 'membership',
                'family' => $claim->subjectLabel,
                'title' => $claim->subjectTitle,
                'subtitle' => $claim->roleLabel,
                'summary' => $summary !== '' ? $summary : null,
                'status' => $claim->application->status,
                'status_label' => $presenter->statusLabel($claim->application->status),
                'status_color' => $presenter->statusTone($claim->application->status),
                'tone' => $presenter->statusTone($claim->application->status),
                'occurred_at' => $claim->application->submittedAt,
                'proposer_note' => null,
                'note' => $claim->reviewerNote,
                'url' => $claim->subjectPublicUrl,
            ];
        }

        foreach (app(AppFeedbackReader::class)->recentForReporter($accountId, 5) as $feedback) {
            $summary = implode(' · ', array_values(array_filter([
                Str::limit((string) $feedback->description, 90),
                $feedback->visibleMessagesCount > 0
                    ? (string) trans_choice(':count reply|:count replies', $feedback->visibleMessagesCount, ['count' => $feedback->visibleMessagesCount])
                    : null,
            ])));

            $items[] = [
                'key' => 'feedback-'.$feedback->id,
                'inspect_key' => 'feedback:'.$feedback->id,
                'tab' => 'feedback',
                'family' => $feedback->categoryLabel,
                'title' => $feedback->title,
                'subtitle' => null,
                'summary' => $summary !== '' ? $summary : null,
                'status' => $feedback->status,
                'status_label' => $feedback->statusLabel,
                'status_color' => $presenter->statusTone($feedback->status),
                'tone' => $presenter->statusTone($feedback->status),
                'occurred_at' => $feedback->createdAt?->toIso8601String(),
                'proposer_note' => null,
                'note' => $feedback->resolutionNote,
                'url' => $feedback->threadUrl,
            ];
        }

        usort($items, static fn (array $left, array $right): int => strcmp(
            (string) $right['occurred_at'],
            (string) $left['occurred_at'],
        ));

        /** @var list<array{
         *     key: string,
         *     inspect_key: string,
         *     tab: string,
         *     family: string,
         *     title: string,
         *     subtitle: ?string,
         *     summary: ?string,
         *     status: string,
         *     status_label: string,
         *     status_color: string,
         *     tone: string,
         *     occurred_at: ?string,
         *     proposer_note: ?string,
         *     note: ?string,
         *     url: ?string
         * }> $activity
         */
        $activity = array_slice($items, 0, 8);

        return $activity;
    }

    /**
     * Structured submission receipt for the contribution selected through
     * `?lihat=`, resolved strictly from the viewer's own records.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function inspectedItem(): ?array
    {
        $inspect = $this->normalizeInspectKey($this->inspect);

        if ($inspect === null) {
            return null;
        }

        /** @var User $user */
        $user = auth()->user();
        $accountId = (string) $user->getKey();
        $presenter = app(ContributionsHubPresenter::class);

        [$kind, $id] = explode(':', $inspect, 2);

        return match ($kind) {
            'request' => $this->inspectedContributionRequest($accountId, $id, $presenter),
            'event' => $this->inspectedEventSubmission($accountId, $id, $presenter),
            'report' => $this->inspectedReport($accountId, $id, $presenter),
            'claim' => $this->inspectedMembershipClaim($accountId, $id, $presenter),
            'feedback' => $this->inspectedAppFeedback($accountId, $id, $presenter),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function inspectedContributionRequest(
        string $accountId,
        string $id,
        ContributionsHubPresenter $presenter,
    ): ?array {
        $ownedId = app(ContributionRequestReader::class)->findRequestIdForProposer($accountId, $id);

        if ($ownedId === null) {
            return null;
        }

        $request = OwnerContext::withOwner(null, fn (): ?ContributionRequest => ContributionRequest::query()
            ->with(['entity', 'reviewer', 'media'])
            ->find($ownedId));

        if (! $request instanceof ContributionRequest) {
            return null;
        }

        $status = $this->scalarValue($request->status);
        $isUpdate = $request->type === ContributionRequestType::Update;

        return [
            'kind' => 'request',
            'request_type' => $this->scalarValue($request->type),
            'id' => (string) $request->getKey(),
            'family_label' => $presenter->subjectLabel($request->subject_type),
            'title' => $presenter->entityTitle($request),
            'status' => $status,
            'status_label' => $presenter->statusLabel($status),
            'status_tone' => $presenter->statusTone($status),
            'submitted_at' => $presenter->formatDateTime($request->created_at),
            'reviewed_at' => $presenter->formatDateTime($request->reviewed_at),
            'approved_at' => $presenter->formatDateTime($request->approved_at),
            'rejected_at' => $presenter->formatDateTime($request->rejected_at),
            'cancelled_at' => $presenter->formatDateTime($request->cancelled_at),
            'reason_label' => $presenter->rejectionReasonLabel($request->reason_code),
            'proposer_note' => filled($request->proposer_note) ? (string) $request->proposer_note : null,
            'reviewer_note' => filled($request->reviewer_note) ? (string) $request->reviewer_note : null,
            'changes' => $isUpdate ? $presenter->changedFieldSummaries($request, 500) : [],
            'submitted_fields' => $isUpdate ? [] : $presenter->submittedFieldSummaries($request, 500),
            'media' => $presenter->stagedMediaItems($request),
            'public_url' => $presenter->requestPublicUrl($request),
            'can_cancel' => $request->isPending(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function inspectedEventSubmission(
        string $accountId,
        string $id,
        ContributionsHubPresenter $presenter,
    ): ?array {
        $card = OwnerContext::withOwner(
            null,
            fn (): ?EventSubmissionCard => app(EventSubmissions::class)->findCardForSubmitter($accountId, $id),
        );

        if (! $card instanceof EventSubmissionCard) {
            return null;
        }

        $status = (string) ($card->presentation['status_value'] ?? '');

        return [
            'kind' => 'event',
            'id' => $card->id,
            'family_label' => $card->occurrenceId !== null ? __('Occurrence') : __('Event'),
            'title' => (string) ($card->presentation['title'] ?? $card->eventTitle ?? __('Event')),
            'parent_title' => $card->presentation['parent_title'] ?? null,
            'status_label' => (string) ($card->presentation['status_label'] ?? $presenter->statusLabel($status)),
            'status_tone' => $presenter->statusTone($status),
            'submitted_at' => $presenter->formatDateTime($card->createdAt),
            'date_label' => $card->presentation['date_label'] ?? null,
            'time_label' => $card->presentation['time_label'] ?? null,
            'details' => $card->details,
            'notes' => $card->notes,
            'public_url' => $card->presentation['url'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function inspectedReport(
        string $accountId,
        string $id,
        ContributionsHubPresenter $presenter,
    ): ?array {
        $report = OwnerContext::withOwner(null, fn (): ?ReportSnapshot => app(ReportReader::class)->find($id));

        if (! $report instanceof ReportSnapshot || $report->reporterId !== $accountId) {
            return null;
        }

        return [
            'kind' => 'report',
            'id' => $report->id,
            'family_label' => __('Report').' · '.$report->entityLabel,
            'title' => $report->entityTitle ?? $report->entityLabel,
            'status' => $report->status,
            'status_label' => $presenter->statusLabel($report->status),
            'status_tone' => $presenter->statusTone($report->status),
            'submitted_at' => $presenter->formatDateTime($report->createdAt),
            'category_label' => $report->categoryLabel,
            'description' => $report->description,
            'resolution_note' => $report->resolutionNote,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function inspectedMembershipClaim(
        string $accountId,
        string $id,
        ContributionsHubPresenter $presenter,
    ): ?array {
        $claim = OwnerContext::withOwner(
            null,
            fn (): ?MembershipApplicationDetail => app(MembershipApplications::class)->find($id),
        );

        if (! $claim instanceof MembershipApplicationDetail || $claim->application->applicantId !== $accountId) {
            return null;
        }

        $status = $claim->application->status;

        return [
            'kind' => 'claim',
            'id' => $claim->application->id,
            'family_label' => __('Membership Claim').' · '.$claim->subjectLabel,
            'title' => $claim->subjectTitle,
            'status' => $status,
            'status_label' => $presenter->statusLabel($status),
            'status_tone' => $presenter->statusTone($status),
            'submitted_at' => $presenter->formatDateTime($claim->application->submittedAt),
            'reviewed_at' => $presenter->formatDateTime($claim->reviewedAt),
            'cancelled_at' => $presenter->formatDateTime($claim->cancelledAt),
            'role_label' => __($claim->roleLabel),
            'granted_role' => $claim->grantedRole === null ? null : __($claim->grantedRole),
            'justification' => $claim->justification,
            'notes' => $claim->notes,
            'reviewer_note' => $claim->reviewerNote,
            'evidence' => array_map(
                static fn (MembershipApplicationEvidence $evidence): array => $evidence->toArray(),
                $claim->evidence,
            ),
            'public_url' => $claim->subjectPublicUrl,
            'can_cancel' => $claim->application->status === 'pending',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function inspectedAppFeedback(
        string $accountId,
        string $id,
        ContributionsHubPresenter $presenter,
    ): ?array {
        $feedback = app(AppFeedbackReader::class)->findForReporter($accountId, $id);

        if (! $feedback instanceof AppFeedbackSnapshot) {
            return null;
        }

        return [
            'kind' => 'feedback',
            'id' => $feedback->id,
            'family_label' => __('App Feedback').' · '.$feedback->categoryLabel,
            'title' => $feedback->title,
            'status' => $feedback->status,
            'status_label' => $feedback->statusLabel,
            'status_tone' => $presenter->statusTone($feedback->status),
            'submitted_at' => $presenter->formatDateTime($feedback->createdAt),
            'description' => $feedback->description,
            'page_url' => $feedback->pageUrl,
            'resolution_note' => $feedback->resolutionNote,
            'replies_count' => $feedback->visibleMessagesCount,
            'thread_url' => $feedback->threadUrl,
        ];
    }

    /** @return LengthAwarePaginator<int, EventSubmissionCard> */
    #[Computed]
    public function submittedEvents(): LengthAwarePaginator
    {
        /** @var User $user */
        $user = auth()->user();

        $submissionPage = OwnerContext::withOwner(
            null,
            fn (): EventSubmissionPage => app(EventSubmissions::class)->paginateForSubmitter(
                (string) $user->getKey(),
                perPage: 8,
                pageName: 'events_page',
                statusGroup: $this->statusFilter !== 'all' ? $this->statusFilter : null,
                search: $this->search !== '' ? trim($this->search) : null,
            ),
        );

        return new LengthAwarePaginator(
            $submissionPage->items,
            $submissionPage->total,
            $submissionPage->perPage,
            $submissionPage->currentPage,
            [
                'pageName' => 'events_page',
                'path' => Paginator::resolveCurrentPath(),
                'query' => request()->query(),
            ],
        );
    }

    /**
     * New directory records proposed by the viewer.
     *
     * @return LengthAwarePaginator<int, ContributionRequest>
     */
    #[Computed]
    public function directorySubmissions(): LengthAwarePaginator
    {
        return OwnerContext::withOwner(null, fn (): LengthAwarePaginator => $this->requestQuery(ContributionRequestType::Create)
            ->with(['entity', 'reviewer', 'media'])
            ->latest('created_at')
            ->paginate(perPage: 8, pageName: 'directory_page'));
    }

    /**
     * Update suggestions proposed by the viewer.
     *
     * @return LengthAwarePaginator<int, ContributionRequest>
     */
    #[Computed]
    public function updateRequests(): LengthAwarePaginator
    {
        return OwnerContext::withOwner(null, fn (): LengthAwarePaginator => $this->requestQuery(ContributionRequestType::Update)
            ->with(['entity', 'reviewer', 'media'])
            ->latest('created_at')
            ->paginate(perPage: 8, pageName: 'updates_page'));
    }

    /** @return LengthAwarePaginator<int, ReportSnapshot> */
    #[Computed]
    public function myReports(): LengthAwarePaginator
    {
        /** @var User $user */
        $user = auth()->user();

        $reportPage = OwnerContext::withOwner(
            null,
            fn (): ReportPage => app(ReportReader::class)->pageForReporter(
                (string) $user->getKey(),
                perPage: 8,
                page: Paginator::resolveCurrentPage('reports_page'),
                status: $this->statusFilter !== 'all' ? $this->statusFilter : null,
                search: $this->search !== '' ? trim($this->search) : null,
            ),
        );

        return new LengthAwarePaginator(
            $reportPage->items,
            $reportPage->total,
            $reportPage->perPage,
            $reportPage->currentPage,
            [
                'pageName' => 'reports_page',
                'path' => Paginator::resolveCurrentPath(),
                'query' => request()->query(),
            ],
        );
    }

    /**
     * Membership claims filed by the viewer, filtered in memory.
     *
     * @return Collection<int, MembershipApplicationDetail>
     */
    #[Computed]
    public function myMembershipClaims(): Collection
    {
        $status = $this->statusFilter;
        $term = mb_strtolower(trim($this->search));

        /** @var Collection<int, MembershipApplicationDetail> $filtered */
        $filtered = $this->allMembershipClaims
            ->filter(function (MembershipApplicationDetail $claim) use ($status, $term): bool {
                if ($status !== 'all' && $claim->application->status !== $status) {
                    return false;
                }

                if ($term === '') {
                    return true;
                }

                $haystack = mb_strtolower(implode(' ', array_filter([
                    $claim->subjectTitle,
                    $claim->subjectLabel,
                    $claim->statusLabel,
                    $claim->justification,
                ])));

                return str_contains($haystack, $term);
            })
            ->values();

        return $filtered;
    }

    /**
     * Unfiltered membership claims filed by the viewer.
     *
     * @return Collection<int, MembershipApplicationDetail>
     */
    #[Computed]
    public function allMembershipClaims(): Collection
    {
        /** @var User $user */
        $user = auth()->user();
        $accountId = (string) $user->getKey();

        /** @var list<MembershipApplicationDetail> $claims */
        $claims = OwnerContext::withOwner(
            null,
            fn (): array => app(MembershipApplications::class)->forApplicant($accountId),
        );

        return collect($claims);
    }

    /**
     * Application feedback tickets reported by the viewer.
     *
     * @return LengthAwarePaginator<int, AppFeedbackSnapshot>
     */
    #[Computed]
    public function myAppFeedback(): LengthAwarePaginator
    {
        /** @var User $user */
        $user = auth()->user();

        $page = app(AppFeedbackReader::class)->pageForReporter(
            (string) $user->getKey(),
            perPage: 8,
            page: Paginator::resolveCurrentPage('feedback_page'),
            status: $this->statusFilter !== 'all' ? $this->statusFilter : null,
            search: $this->search !== '' ? trim($this->search) : null,
        );

        return new LengthAwarePaginator(
            $page->items,
            $page->total,
            $page->perPage,
            $page->currentPage,
            [
                'pageName' => 'feedback_page',
                'path' => Paginator::resolveCurrentPath(),
                'query' => request()->query(),
            ],
        );
    }

    /**
     * Records matching the record-finder query for the chosen mode.
     *
     * @return list<array{title: string, subtitle: ?string, url: string}>
     */
    #[Computed]
    public function finderResults(): array
    {
        if (! $this->showRecordFinder) {
            return [];
        }

        $mode = $this->finderMode;
        $subjectType = $this->finderSubjectType;
        $term = trim($this->finderQuery);

        /** @var list<array{title: string, subtitle: ?string, url: string}> $results */
        $results = collect($this->contributionTargets($subjectType, $term, 12))
            ->map(fn (array $target): array => [
                'title' => (string) ($target['title'] ?? ''),
                'subtitle' => is_string($target['subtitle'] ?? null) ? $target['subtitle'] : null,
                'url' => $this->finderTargetUrl($mode, $subjectType, [
                    'id' => (string) ($target['id'] ?? ''),
                    'slug' => (string) ($target['slug'] ?? ''),
                ]),
            ])
            ->values()
            ->all();

        return $results;
    }

    /**
     * Institution or speaker options for the inline membership claim search.
     *
     * @return list<array{identifier: string, label: string, subtitle: ?string}>
     */
    #[Computed]
    public function claimSearchResults(): array
    {
        $subjectType = MemberSubjectType::tryFrom($this->claimSubjectType) ?? MemberSubjectType::Institution;
        $term = trim($this->claimSearch);

        /** @var list<array{id: string, slug: string, title: string, subtitle: ?string}> $targets */
        $targets = OwnerContext::withOwner(null, fn (): array => match ($subjectType) {
            MemberSubjectType::Institution => app(InstitutionLookup::class)->searchPublicContributionTargets($term, 12),
            MemberSubjectType::Person => app(PersonLookup::class)->searchPublicContributionTargets($term, 12),
            default => [],
        });

        /** @var list<array{identifier: string, label: string, subtitle: ?string}> $results */
        $results = collect($targets)
            ->map(fn (array $target): array => [
                'identifier' => $subjectType === MemberSubjectType::Institution ? $target['id'] : $target['slug'],
                'label' => $target['title'],
                'subtitle' => $target['subtitle'],
            ])
            ->values()
            ->all();

        return $results;
    }

    /** @return array<string, string> */
    #[Computed]
    public function statusFilterOptions(): array
    {
        $presenter = app(ContributionsHubPresenter::class);

        $statuses = match ($this->activeTab) {
            'events' => ['pending', 'published', 'attention'],
            'reports' => ['open', 'triaged', 'resolved', 'dismissed'],
            'feedback' => ['new', 'triaged', 'resolved', 'declined'],
            default => ['pending', 'approved', 'rejected', 'cancelled'],
        };

        $options = ['all' => __('All Statuses')];

        foreach ($statuses as $status) {
            $options[$status] = match ($status) {
                'published' => __('Published'),
                'attention' => __('Needs Attention'),
                default => $presenter->statusLabel($status),
            };
        }

        return $options;
    }

    /** @return array<string, string> */
    #[Computed]
    public function subjectFilterOptions(): array
    {
        $presenter = app(ContributionsHubPresenter::class);

        $options = ['all' => __('All Types')];

        foreach (ContributionSubjectType::cases() as $subjectType) {
            if ($this->activeTab === 'directory' && $subjectType === ContributionSubjectType::Event) {
                continue;
            }

            $options[$subjectType->value] = $presenter->subjectLabel($subjectType);
        }

        return $options;
    }

    /** @return array<string, string> */
    #[Computed]
    public function finderSubjectOptions(): array
    {
        $presenter = app(ContributionsHubPresenter::class);

        $options = [];

        foreach ($this->finderSubjectTypes($this->finderMode) as $subjectType) {
            $options[$subjectType] = $presenter->subjectLabel($subjectType);
        }

        return $options;
    }

    /** @return array{title: string, hint: string} */
    #[Computed]
    public function finderModeCopy(): array
    {
        return match ($this->finderMode) {
            'report' => [
                'title' => __('Report a record'),
                'hint' => __('Pick the event, institution, speaker or reference you want to report.'),
            ],
            'claim' => [
                'title' => __('Claim a record'),
                'hint' => __('Pick the institution or speaker profile you represent.'),
            ],
            default => [
                'title' => __('Suggest an update'),
                'hint' => __('Pick the event, institution, speaker or reference you want to correct.'),
            ],
        };
    }

    public function finderBrowseUrl(): string
    {
        return match ($this->finderSubjectType) {
            ContributionSubjectType::Institution->value => route('institutions.index'),
            ContributionSubjectType::Person->value => route('persons.index'),
            ContributionSubjectType::Reference->value => route('references.index'),
            default => route('events.index'),
        };
    }

    public function render(): View
    {
        return view('livewire.pages.contributions.index', [
            'presenter' => new ContributionsHubPresenter,
        ]);
    }

    /**
     * @return Builder<ContributionRequest>
     */
    private function requestQuery(ContributionRequestType $type): Builder
    {
        /** @var User $user */
        $user = auth()->user();

        $query = ContributionRequest::query()
            ->where('proposer_id', (string) $user->getKey())
            ->where('type', $type);

        if ($this->statusFilter !== 'all') {
            $status = ContributionRequestStatus::tryFrom($this->statusFilter);

            if ($status instanceof ContributionRequestStatus) {
                $query->where('status', $status);
            }
        }

        if ($this->subjectFilter !== 'all') {
            $subjectType = ContributionSubjectType::tryFrom($this->subjectFilter);

            if ($subjectType instanceof ContributionSubjectType) {
                $query->where('subject_type', $subjectType);
            }
        }

        $this->applyRequestSearch($query);

        return $query;
    }

    /**
     * @param  Builder<ContributionRequest>  $query
     */
    private function applyRequestSearch(Builder $query): void
    {
        $term = trim($this->search);

        if ($term === '') {
            return;
        }

        $pattern = "%{$term}%";
        $columns = $this->searchableEntityColumns();

        $query->where(function (Builder $searchQuery) use ($pattern, $columns): void {
            $searchQuery
                ->whereLike('proposer_note', $pattern)
                ->orWhereLike('reviewer_note', $pattern);

            $this->applyProposedDataSearch($searchQuery, $pattern);

            if ($columns === []) {
                return;
            }

            $searchQuery->orWhereHasMorph(
                'entity',
                array_keys($columns),
                function (Builder $entityQuery, string $type) use ($columns, $pattern): void {
                    $entityQuery->where(function (Builder $titleQuery) use ($columns, $type, $pattern): void {
                        foreach ($columns[$type] ?? [] as $column) {
                            $titleQuery->orWhereLike($column, $pattern);
                        }
                    });
                },
            );
        });
    }

    /**
     * @param  Builder<ContributionRequest>  $query
     */
    private function applyProposedDataSearch(Builder $query, string $pattern): void
    {
        $connection = DB::connection();
        $column = $connection->getQueryGrammar()->wrap('proposed_data');
        $isPostgres = $connection->getDriverName() === 'pgsql';
        $operator = $isPostgres ? 'ILIKE' : 'LIKE';
        $expression = $isPostgres
            ? "COALESCE({$column}::text, '')"
            : "COALESCE(CAST({$column} AS TEXT), '')";

        $query->orWhereRaw("{$expression} {$operator} ?", [$pattern]);
    }

    /**
     * @return array<class-string, list<string>>
     */
    private function searchableEntityColumns(): array
    {
        $aliases = [
            ContributionSubjectType::Institution->value => ['name'],
            ContributionSubjectType::Person->value => ['name'],
            ContributionSubjectType::Reference->value => ['title'],
            ContributionSubjectType::Event->value => ['title'],
        ];

        $columns = [];

        foreach ($aliases as $alias => $titleColumns) {
            $modelClass = MorphModelResolver::tryResolve($alias);

            if ($modelClass !== null) {
                $columns[$modelClass] = $titleColumns;
            }
        }

        return $columns;
    }

    /**
     * @return list<array{id: string, slug: string, title: string, subtitle: ?string}>
     */
    private function contributionTargets(string $subjectType, string $search, int $limit): array
    {
        /** @var list<array{id: string, slug: string, title: string, subtitle: ?string}> $targets */
        $targets = match ($subjectType) {
            ContributionSubjectType::Event->value => array_map(
                static function (array $target): array {
                    $slug = (string) ($target['slug'] ?? '');
                    $subtitle = $target['subtitle'] ?? null;

                    return [
                        'id' => $slug,
                        'slug' => $slug,
                        'title' => (string) ($target['title'] ?? ''),
                        'subtitle' => is_string($subtitle) && $subtitle !== '' ? $subtitle : null,
                    ];
                },
                OwnerContext::withOwner(
                    null,
                    fn (): array => app(EventSubmissions::class)->searchPublicContributionTargets($search, $limit),
                ),
            ),
            ContributionSubjectType::Institution->value => OwnerContext::withOwner(
                null,
                fn (): array => app(InstitutionLookup::class)->searchPublicContributionTargets($search, $limit),
            ),
            ContributionSubjectType::Person->value => OwnerContext::withOwner(
                null,
                fn (): array => app(PersonLookup::class)->searchPublicContributionTargets($search, $limit),
            ),
            ContributionSubjectType::Reference->value => OwnerContext::withOwner(
                null,
                fn (): array => app(ReferenceLookup::class)->searchPublicContributionTargets($search, $limit),
            ),
            default => [],
        };

        return array_values($targets);
    }

    /**
     * @param  array{id: string, slug: string}  $target
     */
    private function finderTargetUrl(string $mode, string $subjectType, array $target): string
    {
        $routeSegment = ContributionSubjectType::from($subjectType)->publicRouteSegment();
        $identifier = $mode === 'claim' && $subjectType === ContributionSubjectType::Institution->value
            ? $target['id']
            : $target['slug'];

        return match ($mode) {
            'claim' => route('membership-applications.create', [
                'subjectType' => $routeSegment,
                'subjectId' => $identifier,
            ]),
            'report' => route('reports.create', [
                'subjectType' => $routeSegment,
                'subjectId' => $identifier,
            ]),
            default => route('contributions.suggest-update', [
                'subjectType' => $routeSegment,
                'subjectId' => $identifier,
            ]),
        };
    }

    /**
     * @return list<string>
     */
    private function finderSubjectTypes(string $mode): array
    {
        if ($mode === 'claim') {
            return [ContributionSubjectType::Institution->value, ContributionSubjectType::Person->value];
        }

        return array_map(
            static fn (ContributionSubjectType $subjectType): string => $subjectType->value,
            ContributionSubjectType::cases(),
        );
    }

    /**
     * @return list<string>
     */
    private function paginationPageNames(): array
    {
        return ['events_page', 'directory_page', 'updates_page', 'reports_page', 'feedback_page'];
    }

    private function resetPaginationPages(): void
    {
        foreach ($this->paginationPageNames() as $pageName) {
            $this->resetPage($pageName);
        }
    }

    /**
     * @return list<string>
     */
    private function tabs(): array
    {
        return ['overview', 'events', 'directory', 'updates', 'reports', 'membership', 'feedback'];
    }

    private function normalizeTab(string $tab): string
    {
        return in_array($tab, $this->tabs(), true) ? $tab : 'overview';
    }

    private function normalizeStatusFilter(string $status): string
    {
        $allowed = [
            'all', 'pending', 'approved', 'rejected', 'cancelled',
            'open', 'triaged', 'resolved', 'dismissed', 'new', 'declined',
            'published', 'attention',
        ];

        return in_array($status, $allowed, true) ? $status : 'all';
    }

    private function normalizeSubjectFilter(string $subject): string
    {
        return ContributionSubjectType::tryFrom($subject) instanceof ContributionSubjectType ? $subject : 'all';
    }

    private function normalizeClaimSubjectType(string $claimSubjectType): string
    {
        return in_array($claimSubjectType, [
            MemberSubjectType::Institution->value,
            MemberSubjectType::Person->value,
        ], true) ? $claimSubjectType : MemberSubjectType::Institution->value;
    }

    private function normalizeInspectKey(?string $key): ?string
    {
        if (! is_string($key)) {
            return null;
        }

        $candidate = strtolower(trim($key));

        return preg_match('/^(request|event|report|claim|feedback):[0-9a-f-]{36}$/', $candidate) === 1
            ? $candidate
            : null;
    }

    private function scalarValue(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function recordSignal(SignalRecorder $signals, string $eventName, array $properties): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        $signals->record(
            eventName: $eventName,
            eventCategory: 'contribution',
            actorId: (string) $user->getKey(),
            properties: $properties,
            request: request(),
        );
    }
}
