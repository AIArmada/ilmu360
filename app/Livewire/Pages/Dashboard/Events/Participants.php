<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Dashboard\Events;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Models\EventAttendance;
use AIArmada\Events\Models\EventRegistrationParticipant;
use App\Actions\Events\RecordEventParticipantCheckInAction;
use App\Actions\Events\UpdateEventParticipantAttendanceAction;
use App\Enums\EventAttendanceStatus;
use App\Livewire\Concerns\InteractsWithToasts;
use App\Models\Event;
use App\Models\User;
use App\Support\Events\EventCommercePolicy;
use App\Support\Events\ParticipantIdentity;
use App\Support\Timezone\UserDateTimeFormatter;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

#[Layout('layouts.app')]
#[Title('Event participants')]
final class Participants extends Component
{
    use InteractsWithToasts;
    use WithPagination;

    #[Locked]
    public string $eventId = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'attendance', except: 'all')]
    public string $attendanceFilter = 'all';

    #[Url(as: 'scope', except: 'event')]
    public string $scope = 'event';

    #[Url(as: 'occurrence', except: '')]
    public string $occurrenceId = '';

    #[Url(as: 'session', except: '')]
    public string $sessionId = '';

    /** @var list<string> */
    public array $selectedParticipantIds = [];

    public string $attendanceNote = '';

    public function boot(): void
    {
        OwnerContext::setForRequest(null);
    }

    public function mount(Event $event): void
    {
        $user = $this->currentUser();

        abort_unless($user instanceof User, 403);
        abort_unless($user->can('viewRegistrations', $event), 403);

        $this->eventId = (string) $event->getKey();
        $this->attendanceFilter = $this->normalizeAttendanceFilter($this->attendanceFilter);
        $this->scope = $this->normalizeScope($this->scope);
        $this->normalizeScopeSelection($event);
    }

    public function updatedSearch(): void
    {
        $this->resetPage('participants_page');
        $this->clearSelection();
    }

    public function updatedAttendanceFilter(mixed $value): void
    {
        $this->attendanceFilter = $this->normalizeAttendanceFilter($value);
        $this->resetPage('participants_page');
        $this->clearSelection();
    }

    public function updatedScope(mixed $value): void
    {
        $this->scope = $this->normalizeScope($value);
        $this->sessionId = '';

        if ($this->scope === 'occurrence' && $this->occurrenceId === '') {
            $this->occurrenceId = array_key_first($this->occurrenceOptions()) ?? '';
        }

        if ($this->scope !== 'occurrence' && $this->scope !== 'session') {
            $this->occurrenceId = '';
        }

        $this->resetPage('participants_page');
        $this->clearSelection();
    }

    public function updatedOccurrenceId(mixed $value): void
    {
        $this->occurrenceId = is_string($value) ? $value : '';
        $this->sessionId = '';
        $this->resetPage('participants_page');
        $this->clearSelection();
    }

    public function updatedSessionId(mixed $value): void
    {
        $this->sessionId = is_string($value) ? $value : '';
        $this->resetPage('participants_page');
        $this->clearSelection();
    }

    public function selectVisibleParticipants(): void
    {
        $visibleIds = collect($this->participantPage()->items())
            ->filter(fn (mixed $participant): bool => $participant instanceof EventRegistrationParticipant)
            ->map(fn (EventRegistrationParticipant $participant): string => (string) $participant->getKey())
            ->all();

        $this->selectedParticipantIds = array_values(array_unique([
            ...$this->selectedParticipantIds,
            ...$visibleIds,
        ]));
    }

    public function clearSelection(): void
    {
        $this->selectedParticipantIds = [];
    }

    public function checkInParticipant(
        string $participantId,
        RecordEventParticipantCheckInAction $recordCheckIn,
    ): void {
        $event = $this->selectedEvent();
        $this->authorizeManageAttendance($event);

        if (! $this->checkInEnabled($event)) {
            $this->errorToast(__('Live check-in is turned off for this event.'));

            return;
        }

        $participant = $this->participantForAction($event, $participantId);

        try {
            $result = OwnerContext::withOwner(null, fn (): array => $recordCheckIn->handle(
                event: $event,
                participant: $participant,
                staff: $this->currentUserOrAbort(),
                occurrenceId: $this->selectedOccurrenceId($event),
                sessionId: $this->selectedSessionId($event),
            ));

            $this->successToast($result['status'] === 'duplicate'
                ? __('This participant is already checked in for the selected scope.')
                : __('Participant checked in successfully.'));
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $this->errorToast(__('We could not check in this participant. Please try again.'));
        }
    }

    public function markParticipantAttendance(
        string $participantId,
        string $status,
        UpdateEventParticipantAttendanceAction $updateAttendance,
    ): void {
        $event = $this->selectedEvent();
        $this->authorizeManageAttendance($event);
        $attendanceStatus = EventAttendanceStatus::tryFrom($status);

        if (! $attendanceStatus instanceof EventAttendanceStatus) {
            throw ValidationException::withMessages([
                'attendance' => __('Select a valid attendance status.'),
            ]);
        }

        $participant = $this->participantForAction($event, $participantId);

        try {
            OwnerContext::withOwner(null, function () use ($attendanceStatus, $event, $participant, $updateAttendance): void {
                if ($attendanceStatus === EventAttendanceStatus::NotRecorded) {
                    $updateAttendance->clear(
                        event: $event,
                        participant: $participant,
                        actor: $this->currentUserOrAbort(),
                        occurrenceId: $this->selectedOccurrenceId($event),
                        sessionId: $this->selectedSessionId($event),
                        note: $this->normalizedNote(),
                    );

                    return;
                }

                $updateAttendance->handle(
                    event: $event,
                    participant: $participant,
                    status: $attendanceStatus,
                    actor: $this->currentUserOrAbort(),
                    occurrenceId: $this->selectedOccurrenceId($event),
                    sessionId: $this->selectedSessionId($event),
                    note: $this->normalizedNote(),
                );
            });

            $this->attendanceNote = '';
            $this->successToast(__('Attendance updated successfully.'));
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $this->errorToast(__('We could not update attendance. Please try again.'));
        }
    }

    public function markSelectedAttendance(
        string $status,
        UpdateEventParticipantAttendanceAction $updateAttendance,
    ): void {
        $event = $this->selectedEvent();
        $this->authorizeManageAttendance($event);
        $attendanceStatus = EventAttendanceStatus::tryFrom($status);

        if (! $attendanceStatus instanceof EventAttendanceStatus) {
            throw ValidationException::withMessages([
                'attendance' => __('Select a valid attendance status.'),
            ]);
        }

        $participantIds = array_values(array_filter(
            $this->selectedParticipantIds,
            is_string(...),
        ));

        if ($participantIds === []) {
            $this->errorToast(__('Select at least one participant first.'));

            return;
        }

        try {
            $updatedCount = OwnerContext::withOwner(null, function () use (
                $attendanceStatus,
                $event,
                $participantIds,
                $updateAttendance,
            ): int {
                return DB::transaction(function () use (
                    $attendanceStatus,
                    $event,
                    $participantIds,
                    $updateAttendance,
                ): int {
                    $updatedCount = 0;

                    foreach ($participantIds as $participantId) {
                        $participant = $this->participantForAction($event, $participantId);

                        if ($attendanceStatus === EventAttendanceStatus::NotRecorded) {
                            $updateAttendance->clear(
                                event: $event,
                                participant: $participant,
                                actor: $this->currentUserOrAbort(),
                                occurrenceId: $this->selectedOccurrenceId($event),
                                sessionId: $this->selectedSessionId($event),
                                note: $this->normalizedNote(),
                            );
                        } else {
                            $updateAttendance->handle(
                                event: $event,
                                participant: $participant,
                                status: $attendanceStatus,
                                actor: $this->currentUserOrAbort(),
                                occurrenceId: $this->selectedOccurrenceId($event),
                                sessionId: $this->selectedSessionId($event),
                                note: $this->normalizedNote(),
                            );
                        }

                        $updatedCount++;
                    }

                    return $updatedCount;
                });
            });

            $this->selectedParticipantIds = [];
            $this->attendanceNote = '';
            $this->successToast(trans_choice(
                ':count participant attendance updated.|:count participant attendances updated.',
                $updatedCount,
                ['count' => $updatedCount],
            ));
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $this->errorToast(__('We could not update the selected participants. Please try again.'));
        }
    }

    /** @return LengthAwarePaginator<int, EventRegistrationParticipant> */
    #[Computed]
    public function participantPage(): LengthAwarePaginator
    {
        return $this->participantQuery()->paginate(25, ['*'], 'participants_page');
    }

    /** @return array<string, string> */
    #[Computed]
    public function occurrenceOptions(): array
    {
        return $this->selectedEvent()
            ->occurrences()
            ->orderBy('starts_at')
            ->orderBy('created_at')
            ->get()
            ->mapWithKeys(function (mixed $occurrence): array {
                $startsAt = $occurrence->starts_at;
                $label = $startsAt instanceof CarbonInterface
                    ? UserDateTimeFormatter::translatedFormat($startsAt, 'j M Y, h:i A')
                    : __('Date to be confirmed');

                return [(string) $occurrence->getKey() => $label];
            })
            ->all();
    }

    /** @return array<string, string> */
    #[Computed]
    public function sessionOptions(): array
    {
        $occurrenceId = $this->selectedOccurrenceId($this->selectedEvent());

        if ($occurrenceId === null) {
            return [];
        }

        return $this->selectedEvent()
            ->sessions()
            ->where('event_occurrence_id', $occurrenceId)
            ->orderBy('starts_at')
            ->orderBy('created_at')
            ->get()
            ->mapWithKeys(function (mixed $session): array {
                $startsAt = $session->starts_at;
                $label = $startsAt instanceof CarbonInterface
                    ? UserDateTimeFormatter::translatedFormat($startsAt, 'j M Y, h:i A')
                    : __('Session date to be confirmed');

                return [(string) $session->getKey() => trim((string) $session->title).' · '.$label];
            })
            ->all();
    }

    /** @return array{total: int, attended: int, did_not_attend: int, not_recorded: int} */
    #[Computed]
    public function summaryStats(): array
    {
        $query = $this->participantQuery(applyAttendanceFilter: false);
        $all = (clone $query)->count();
        $attended = (clone $query)
            ->whereHas('attendances', fn (Builder $attendanceQuery): Builder => $this->applyAttendanceScope($attendanceQuery)
                ->where(function (Builder $statusQuery): void {
                    $statusQuery
                        ->where('attendance_type', EventAttendanceStatus::Attended->value)
                        ->orWhere(function (Builder $checkInQuery): void {
                            $checkInQuery
                                ->whereNotNull('checked_in_at')
                                ->where(function (Builder $attendanceTypeQuery): void {
                                    $attendanceTypeQuery
                                        ->whereNull('attendance_type')
                                        ->orWhere('attendance_type', '!=', EventAttendanceStatus::DidNotAttend->value);
                                });
                        });
                }))
            ->count();
        $didNotAttend = (clone $query)
            ->whereHas('attendances', fn (Builder $attendanceQuery): Builder => $this->applyAttendanceScope($attendanceQuery)
                ->where('attendance_type', EventAttendanceStatus::DidNotAttend->value))
            ->count();

        return [
            'total' => $all,
            'attended' => $attended,
            'did_not_attend' => $didNotAttend,
            'not_recorded' => max(0, $all - $attended - $didNotAttend),
        ];
    }

    public function attendanceStatus(EventRegistrationParticipant $participant): string
    {
        $attendance = $this->currentAttendance($participant);

        if (! $attendance instanceof EventAttendance) {
            return 'not_recorded';
        }

        if ($attendance->attendance_type === EventAttendanceStatus::DidNotAttend->value) {
            return EventAttendanceStatus::DidNotAttend->value;
        }

        if ($attendance->attendance_type === EventAttendanceStatus::Attended->value || $attendance->checked_in_at !== null) {
            return EventAttendanceStatus::Attended->value;
        }

        return 'not_recorded';
    }

    public function attendanceLabel(string $status): string
    {
        return match ($status) {
            EventAttendanceStatus::Attended->value => EventAttendanceStatus::Attended->getLabel(),
            EventAttendanceStatus::DidNotAttend->value => EventAttendanceStatus::DidNotAttend->getLabel(),
            EventAttendanceStatus::NotRecorded->value => EventAttendanceStatus::NotRecorded->getLabel(),
            default => __('Not recorded yet'),
        };
    }

    public function participantIdentityLabel(EventRegistrationParticipant $participant): ?string
    {
        $identity = data_get($participant->metadata, 'event_checkout.identity_document');

        return ParticipantIdentity::masked(is_array($identity) ? $identity : null);
    }

    public function checkInEnabled(Event $event): bool
    {
        $setting = data_get(is_array($event->metadata) ? $event->metadata : [], 'registration.check_in_enabled');

        return $setting === null ? true : (bool) $setting;
    }

    public function canManageAttendance(): bool
    {
        return $this->currentUserOrAbort()->can('manageAttendance', $this->selectedEvent());
    }

    public function canManageAdmissions(): bool
    {
        return $this->currentUserOrAbort()->can('manageAdmissions', $this->selectedEvent());
    }

    public function refundsEnabled(Event $event): bool
    {
        return app(EventCommercePolicy::class)->refundsEnabled($event);
    }

    public function printList(): void
    {
        // The actual print action is intentionally browser-local; no participant
        // data leaves the authenticated workspace.
        $this->dispatch('print-participant-list');
    }

    public function exportUrl(): string
    {
        $event = $this->selectedEvent();
        $query = array_filter([
            'q' => trim($this->search),
            'attendance' => $this->attendanceFilter === 'all' ? null : $this->attendanceFilter,
            'occurrence' => $this->selectedOccurrenceId($event),
            'session' => $this->selectedSessionId($event),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        return route('dashboard.events.participants.export', [
            'event' => $event,
            ...$query,
        ]);
    }

    public function render(): View
    {
        $event = $this->selectedEvent();

        return view('livewire.pages.dashboard.events.participants', [
            'event' => $event,
            'participants' => $this->participantPage(),
            'stats' => $this->summaryStats(),
            'isCheckInEnabled' => $this->checkInEnabled($event),
            'canManageAttendance' => $this->canManageAttendance(),
            'canManageAdmissions' => $this->canManageAdmissions(),
        ]);
    }

    /**
     * @return Builder<EventRegistrationParticipant>
     */
    private function participantQuery(bool $applyAttendanceFilter = true): Builder
    {
        $event = $this->selectedEvent();
        $query = EventRegistrationParticipant::query()
            ->with([
                'registration',
                'contactMethods',
                'passes',
                'attendances' => function (Relation $attendanceRelation): void {
                    $this->applyAttendanceScope($attendanceRelation->getQuery());
                },
            ])
            ->where('event_id', $event->getKey())
            ->whereHas('registration', fn (Builder $registrationQuery): Builder => $registrationQuery->whereNotIn('status', [
                'cancelled',
                'rejected',
                'refunded',
                'refund_pending',
                'expired',
            ]));

        $this->applyParticipantScope($query, $event);
        $this->applyParticipantSearch($query);

        if ($applyAttendanceFilter && $this->attendanceFilter !== 'all') {
            if ($this->attendanceFilter === EventAttendanceStatus::Attended->value) {
                $query->whereHas('attendances', fn (Builder $attendanceQuery): Builder => $this->applyAttendanceScope($attendanceQuery)
                    ->where(function (Builder $statusQuery): void {
                        $statusQuery
                            ->where('attendance_type', EventAttendanceStatus::Attended->value)
                            ->orWhere(function (Builder $checkInQuery): void {
                                $checkInQuery
                                    ->whereNotNull('checked_in_at')
                                    ->where(function (Builder $attendanceTypeQuery): void {
                                        $attendanceTypeQuery
                                            ->whereNull('attendance_type')
                                            ->orWhere('attendance_type', '!=', EventAttendanceStatus::DidNotAttend->value);
                                    });
                            });
                    }));
            } elseif ($this->attendanceFilter === EventAttendanceStatus::DidNotAttend->value) {
                $query->whereHas('attendances', fn (Builder $attendanceQuery): Builder => $this->applyAttendanceScope($attendanceQuery)
                    ->where('attendance_type', EventAttendanceStatus::DidNotAttend->value));
            } else {
                $query->whereDoesntHave('attendances', fn (Builder $attendanceQuery): Builder => $this->applyAttendanceScope($attendanceQuery));
            }
        }

        return $query->orderBy('name')->orderBy('created_at');
    }

    /**
     * @param  Builder<EventRegistrationParticipant>  $query
     */
    private function applyParticipantScope(Builder $query, Event $event): void
    {
        $occurrenceId = $this->selectedOccurrenceId($event);
        $sessionId = $this->selectedSessionId($event);

        if ($sessionId !== null) {
            $query->where(function (Builder $scopeQuery) use ($sessionId): void {
                $scopeQuery
                    ->where('event_session_id', $sessionId)
                    ->orWhereHas('registration', fn (Builder $registrationQuery): Builder => $registrationQuery->where('event_session_id', $sessionId));
            });

            return;
        }

        if ($occurrenceId !== null) {
            $query->where(function (Builder $scopeQuery) use ($occurrenceId): void {
                $scopeQuery
                    ->where('event_occurrence_id', $occurrenceId)
                    ->orWhereHas('registration', fn (Builder $registrationQuery): Builder => $registrationQuery->where('event_occurrence_id', $occurrenceId));
            });
        }
    }

    /** @param Builder<EventRegistrationParticipant> $query */
    private function applyParticipantSearch(Builder $query): void
    {
        $term = trim($this->search);

        if ($term === '') {
            return;
        }

        $like = '%'.$term.'%';
        $identityHash = ParticipantIdentity::lookupHash(ParticipantIdentity::normalize($term));

        $query->where(function (Builder $searchQuery) use ($identityHash, $like): void {
            $searchQuery
                ->whereLike('name', $like)
                ->orWhereHas('contactMethods', fn (Builder $contactQuery): Builder => $contactQuery
                    ->whereLike('value', $like)
                    ->orWhereLike('normalized_value', $like))
                ->orWhereHas('registration', fn (Builder $registrationQuery): Builder => $registrationQuery
                    ->whereLike('registration_no', $like)
                    ->orWhereLike('external_order_id', $like))
                ->orWhereLike('metadata', '%"lookup_hash":"'.$identityHash.'"%');
        });
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function applyAttendanceScope(Builder $query): Builder
    {
        $query->whereNull('cancelled_at');

        // Event scope is an overall view. It includes an event-level manual
        // decision and any occurrence/session evidence without rewriting that
        // more detailed evidence into every parent scope.
        if ($this->scope === 'event') {
            return $query;
        }

        $event = $this->selectedEvent();
        $occurrenceId = $this->selectedOccurrenceId($event);
        $sessionId = $this->selectedSessionId($event);

        $query
            ->when(
                $occurrenceId === null,
                fn (Builder $scopeQuery): Builder => $scopeQuery->whereNull('event_occurrence_id'),
                fn (Builder $scopeQuery): Builder => $scopeQuery->where('event_occurrence_id', $occurrenceId),
            )
            ->when(
                $sessionId === null,
                fn (Builder $scopeQuery): Builder => $scopeQuery->whereNull('event_session_id'),
                fn (Builder $scopeQuery): Builder => $scopeQuery->where('event_session_id', $sessionId),
            );

        return $query;
    }

    private function currentAttendance(EventRegistrationParticipant $participant): ?EventAttendance
    {
        $attendances = $participant->relationLoaded('attendances')
            ? $participant->getRelation('attendances')
            : $participant->attendances()->whereNull('cancelled_at')->get();

        if (! $attendances instanceof Collection) {
            return null;
        }

        /** @var EventAttendance|null $attendance */
        $attendance = $attendances
            ->filter(fn (mixed $candidate): bool => $candidate instanceof EventAttendance)
            ->sortByDesc('created_at')
            ->first();

        return $attendance;
    }

    private function participantForAction(Event $event, string $participantId): EventRegistrationParticipant
    {
        return EventRegistrationParticipant::query()
            ->whereKey($participantId)
            ->where('event_id', $event->getKey())
            ->whereHas('registration', fn (Builder $query): Builder => $query->whereNotIn('status', [
                'cancelled',
                'rejected',
                'refunded',
                'refund_pending',
                'expired',
            ]))
            ->firstOrFail();
    }

    private function selectedOccurrenceId(Event $event): ?string
    {
        if ($this->scope === 'event') {
            return null;
        }

        if ($this->scope === 'occurrence') {
            return $this->occurrenceBelongsToEvent($event, $this->occurrenceId)
                ? $this->occurrenceId
                : null;
        }

        $session = $event->sessions()->whereKey($this->sessionId)->first();

        return $session?->event_occurrence_id !== null ? (string) $session->event_occurrence_id : null;
    }

    private function selectedSessionId(Event $event): ?string
    {
        if ($this->scope !== 'session' || $this->sessionId === '') {
            return null;
        }

        return $event->sessions()->whereKey($this->sessionId)->exists() ? $this->sessionId : null;
    }

    private function occurrenceBelongsToEvent(Event $event, string $occurrenceId): bool
    {
        return $occurrenceId !== '' && $event->occurrences()->whereKey($occurrenceId)->exists();
    }

    private function normalizeScopeSelection(Event $event): void
    {
        if ($this->scope === 'event') {
            $this->occurrenceId = '';
            $this->sessionId = '';

            return;
        }

        if (! $this->occurrenceBelongsToEvent($event, $this->occurrenceId)) {
            $this->occurrenceId = array_key_first($this->occurrenceOptions()) ?? '';
        }

        if ($this->scope !== 'session') {
            $this->sessionId = '';

            return;
        }

        $sessionExists = $this->sessionId !== ''
            && $event->sessions()->whereKey($this->sessionId)->where('event_occurrence_id', $this->occurrenceId)->exists();

        if (! $sessionExists) {
            $this->sessionId = array_key_first($this->sessionOptions()) ?? '';
        }
    }

    private function normalizeScope(mixed $value): string
    {
        $scope = is_string($value) ? $value : 'event';

        return in_array($scope, ['event', 'occurrence', 'session'], true) ? $scope : 'event';
    }

    private function normalizeAttendanceFilter(mixed $value): string
    {
        $filter = is_string($value) ? $value : 'all';

        return in_array($filter, ['all', 'attended', 'did_not_attend', 'not_recorded'], true) ? $filter : 'all';
    }

    private function normalizedNote(): ?string
    {
        $note = trim($this->attendanceNote);

        return $note === '' ? null : mb_substr($note, 0, 500);
    }

    private function authorizeManageAttendance(Event $event): void
    {
        abort_unless($this->canManageAttendance(), 403);
    }

    private function currentUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    private function currentUserOrAbort(): User
    {
        $user = $this->currentUser();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function selectedEvent(): Event
    {
        /** @var Event $event */
        $event = Event::query()->findOrFail($this->eventId);

        return $event;
    }
}
