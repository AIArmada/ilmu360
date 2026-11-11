<?php

declare(strict_types=1);

namespace App\Features\Registration;

use App\Features\Registration\Actions\RecordSelfCheckInAction;
use App\Features\Registration\Actions\RegisterFreeAdmissionAction;
use App\Features\Registration\Api\AdmissionFacts;
use App\Features\Registration\Api\AdmissionRegistration;
use App\Features\Registration\Api\AttendanceCommands;
use App\Features\Registration\Api\AttendanceState;
use App\Features\Registration\Queries\DatabaseAdmissionFacts;
use App\Features\Registration\Queries\ResolveAttendanceState;
use App\Platform\FeatureModule;

/**
 * Registration owns event admissions, attendees and attendance from R1 onward.
 *
 * R1a claims the free-admission registration tables. The app-side aggregate
 * writer is RegisterFreeAdmissionAction; the package RegisterForFreeAction and
 * its RegistrationService remain the canonical external writers for
 * registrations, participants, answers and items, so the app action declares
 * ownership of the whole aggregate while delegating the actual row writes.
 *
 * R2a claims self check-in and attendance state. The app-side aggregate writer
 * is RecordSelfCheckInAction; the package EventCheckInService
 * (DefaultEventCheckInService) remains the canonical external writer for
 * event_attendances and event_attendance_logs, so the app action declares
 * ownership of the self-check-in aggregate while delegating the actual row
 * writes. Walk-ins, headcounts, staff attendance updates/clears and the staff
 * participants workspace remain outside this claim until R2b.
 *
 * The following writers remain root/package-owned and are recorded for the
 * later migration slices:
 *
 * - root Registration model: booted() repeats the vendor cascade and deletes
 *   check-in rows on a direct registration delete. The vendor cascade runs
 *   from the registration `deleting` hook and does remove participants,
 *   answers, items and attendances for direct deletes, but root keeps the
 *   hook until R6 verifies every delete path and the participant-only
 *   attendance edge.
 * - the dashboard registration-questions workspace is a Registration entry
 *   point that delegates question writes to the package actions; admission
 *   setup remains Events-owned. Paid checkout orchestration, registration
 *   exports and the staff attendance workspace stay in their root or vendor
 *   homes until R2b-R5.
 */
class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->bind(AdmissionFacts::class, DatabaseAdmissionFacts::class);
        $this->app->bind(AdmissionRegistration::class, RegisterFreeAdmissionAction::class);
        $this->app->bind(AttendanceCommands::class, RecordSelfCheckInAction::class);
        $this->app->bind(AttendanceState::class, ResolveAttendanceState::class);
    }
}
