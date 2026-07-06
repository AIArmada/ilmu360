<?php

namespace App\Http\Controllers\Api;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
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
                        $registration->id,
                        $registration->resolvedName() ?? $registrantName,
                        $registration->resolvedEmail() ?? $registrantEmail,
                        $registration->resolvedPhone(),
                        $registration->statusValue(),
                        $registration->registered_at?->toIso8601String()
                            ?? $registration->created_at?->toIso8601String()
                            ?? '',
                    ],
                        escape: '\\');
                }

                fclose($handle);
            });
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }
}
