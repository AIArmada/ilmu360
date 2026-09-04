<?php

namespace App\Http\Controllers\Api;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Enums\EventAttendanceStatus;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use App\Support\Events\ParticipantIdentity;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use OwenIt\Auditing\Models\Audit;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Group('RegistrationExport', 'Authenticated CSV export endpoints for institution event registrations.')]
class RegistrationExportController extends Controller
{
    /**
     * Export registrations for an event as CSV.
     * Per documentation B9d: exports require institution owner/admin role and are audit logged.
     */
    #[Endpoint(
        title: 'Export registrations as CSV',
        description: 'Streams a CSV export of event registrations when the current authenticated user is authorized to export them.',
    )]
    public function export(Request $request, Event $event): StreamedResponse|JsonResponse
    {
        // Check authorization via EventPolicy
        if (Gate::denies('exportRegistrations', $event)) {
            return response()->json([
                'error' => [
                    'code' => 'forbidden',
                    'message' => 'You are not authorized to export registrations for this event.',
                ],
            ], 403);
        }

        $registrationsQuery = Registration::query()
            ->where('event_id', $event->id)
            ->active()
            ->with([
                'registrant',
                'primaryParticipant.contactMethods',
            ])
            ->orderBy('created_at');
        $this->applyFilters($registrationsQuery, $request);

        // Log the export action using Laravel Auditing (per B9d)
        Audit::create([
            'id' => (string) Str::uuid(),
            'user_type' => $request->user()::class,
            'user_id' => $request->user()->id,
            'event' => 'export_registrations',
            'auditable_type' => Event::class,
            'auditable_id' => $event->id,
            'old_values' => [],
            'new_values' => [
                'count' => (clone $registrationsQuery)->count(),
                'exported_at' => now()->toIso8601String(),
            ],
            'url' => $request->fullUrl(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $filename = "registrations-{$event->slug}-".now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($registrationsQuery) {
            OwnerContext::withOwner(null, function () use ($registrationsQuery): void {
                $handle = fopen('php://output', 'w');

                // Header row
                fputcsv($handle, [
                    'Registration ID',
                    'Name',
                    'Email',
                    'Phone',
                    'Status',
                    'Registered At',
                ],
                    escape: '\\');

                foreach ($registrationsQuery->cursor() as $registration) {
                    if (! $registration instanceof Registration) {
                        continue;
                    }

                    $registrant = $registration->registrant;
                    $registrantName = $registrant instanceof User ? $registrant->name : null;
                    $registrantEmail = $registrant instanceof User ? $registrant->email : null;

                    fputcsv($handle, [
                        $this->safeCsvCell($registration->id),
                        $this->safeCsvCell($registration->resolvedName() ?? $registrantName),
                        $this->safeCsvCell($registration->resolvedEmail() ?? $registrantEmail),
                        $this->safeCsvCell($registration->resolvedPhone()),
                        $this->safeCsvCell($registration->statusValue()),
                        $this->safeCsvCell($registration->registered_at?->toIso8601String()
                            ?? $registration->created_at?->toIso8601String()
                            ?? ''),
                    ],
                        escape: '\\');
                }

                fclose($handle);
            });
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    private function safeCsvCell(?string $value): string
    {
        $value ??= '';

        return preg_match('/^\s*[=+\-@]/', $value) === 1
            ? "'{$value}"
            : $value;
    }

    /**
     * @param  Builder<Registration>  $query
     */
    private function applyFilters(Builder $query, Request $request): void
    {
        $term = trim((string) $request->input('q', ''));

        if ($term !== '') {
            $identityHash = ParticipantIdentity::lookupHash(ParticipantIdentity::normalize($term));

            $query->where(function (Builder $searchQuery) use ($identityHash, $term): void {
                $this->whereEscapedLike($searchQuery, 'registration_no', $term);
                $this->whereEscapedLike($searchQuery, 'external_order_id', $term, 'or');
                $searchQuery->orWhereHas('participants', function (Builder $participantQuery) use ($identityHash, $term): void {
                    $this->whereEscapedLike($participantQuery, 'name', $term);
                    $this->whereEscapedLike($participantQuery, 'metadata', '"lookup_hash":"'.$identityHash.'"', 'or');
                    $participantQuery->orWhereHas('contactMethods', function (Builder $contactQuery) use ($term): Builder {
                        $this->whereEscapedLike($contactQuery, 'value', $term);
                        $this->whereEscapedLike($contactQuery, 'normalized_value', $term, 'or');

                        return $contactQuery;
                    });
                });
            });
        }

        $statuses = $this->inputValues($request->input('status'));
        $allowedStatuses = [
            'pending', 'confirmed', 'checked_in', 'no_show', 'completed',
            'cancelled', 'rejected', 'waitlisted', 'refund_pending', 'refunded', 'expired',
        ];
        $statuses = array_values(array_intersect($statuses, $allowedStatuses));

        if ($statuses !== []) {
            $query->whereIn('status', $statuses);
        }

        $paymentStatuses = array_values(array_intersect(
            $this->inputValues($request->input('payment')),
            ['pending', 'paid', 'complimentary', 'refunded'],
        ));

        if ($paymentStatuses !== []) {
            $query->whereIn('payment_status', $paymentStatuses);
        }

        $occurrenceId = $this->singleInputValue($request->input('occurrence'));
        if ($occurrenceId !== null) {
            $query->where('event_occurrence_id', $occurrenceId);
        }

        $sessionId = $this->singleInputValue($request->input('session'));
        if ($sessionId !== null) {
            $query->where('event_session_id', $sessionId);
        }

        $ticketTypeId = $this->singleInputValue($request->input('ticket'));
        if ($ticketTypeId !== null) {
            $query->whereHas('items', fn (Builder $itemQuery): Builder => $itemQuery->where('ticket_type_id', $ticketTypeId));
        }

        $attendance = $this->singleInputValue($request->input('attendance'));

        if ($attendance === 'attended') {
            $query->where(function (Builder $attendanceQuery): void {
                $attendanceQuery
                    ->whereHas('attendances', fn (Builder $query): Builder => $this->attendedAttendanceQuery($query))
                    ->orWhereHas('participants', fn (Builder $participantQuery): Builder => $participantQuery
                        ->whereHas('attendances', fn (Builder $query): Builder => $this->attendedAttendanceQuery($query)));
            });
        } elseif ($attendance === 'did_not_attend') {
            $query->where(function (Builder $attendanceQuery): void {
                $attendanceQuery
                    ->whereHas('attendances', fn (Builder $query): Builder => $this->didNotAttendQuery($query))
                    ->orWhereHas('participants', fn (Builder $participantQuery): Builder => $participantQuery
                        ->whereHas('attendances', fn (Builder $query): Builder => $this->didNotAttendQuery($query)));
            });
        } elseif ($attendance === 'not_recorded') {
            $query
                ->whereDoesntHave('attendances', fn (Builder $query): Builder => $this->activeAttendanceQuery($query))
                ->whereDoesntHave('participants', fn (Builder $participantQuery): Builder => $participantQuery
                    ->whereHas('attendances', fn (Builder $query): Builder => $this->activeAttendanceQuery($query)));
        }

        $agreement = $this->singleInputValue($request->input('agreement'));

        if ($agreement === 'accepted') {
            $query->whereHas('participants', fn (Builder $participantQuery): Builder => $participantQuery
                ->whereLike('metadata', '%"accepted_at"%'));
        } elseif ($agreement === 'missing') {
            $query->whereDoesntHave('participants', fn (Builder $participantQuery): Builder => $participantQuery
                ->whereLike('metadata', '%"accepted_at"%'));
        }
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function activeAttendanceQuery(Builder $query): Builder
    {
        return $query->whereNull('cancelled_at');
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function attendedAttendanceQuery(Builder $query): Builder
    {
        return $this->activeAttendanceQuery($query)->where(function (Builder $statusQuery): void {
            $statusQuery
                ->where('attendance_type', EventAttendanceStatus::Attended->value)
                ->orWhere(function (Builder $checkInQuery): void {
                    $checkInQuery
                        ->whereNotNull('checked_in_at')
                        ->where(function (Builder $typeQuery): void {
                            $typeQuery
                                ->whereNull('attendance_type')
                                ->orWhere('attendance_type', '!=', EventAttendanceStatus::DidNotAttend->value);
                        });
                });
        });
    }

    /**
     * Search a user-provided term literally while retaining a contains match.
     * `%` and `_` are data here, not operators for the export filter.
     *
     * @template TModel of Model
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function whereEscapedLike(Builder $query, string $column, string $term, string $boolean = 'and'): Builder
    {
        $escapedTerm = str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $term,
        );
        $wrappedColumn = $query->getQuery()->getGrammar()->wrap($column);

        return $query->whereRaw(
            "{$wrappedColumn} LIKE ? ESCAPE ?",
            ['%'.$escapedTerm.'%', '\\'],
            $boolean,
        );
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function didNotAttendQuery(Builder $query): Builder
    {
        return $this->activeAttendanceQuery($query)->where('attendance_type', 'did_not_attend');
    }

    /** @return list<string> */
    private function inputValues(mixed $value): array
    {
        $values = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(array_map(
            static fn (mixed $item): ?string => is_scalar($item) && trim((string) $item) !== '' ? trim((string) $item) : null,
            $values,
        )));
    }

    private function singleInputValue(mixed $value): ?string
    {
        $values = $this->inputValues($value);

        return $values[0] ?? null;
    }
}
