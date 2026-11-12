<?php

declare(strict_types=1);

namespace App\Features\Registration;

use AIArmada\CommerceSupport\Events\PaymentRefunded;
use App\Features\Registration\Actions\IssueOfflineAdmissionAction;
use App\Features\Registration\Actions\PrepareAdmissionCheckoutAction;
use App\Features\Registration\Actions\ProcessAdmissionRefundAction;
use App\Features\Registration\Actions\RecordFreeCheckoutMetadataAction;
use App\Features\Registration\Actions\RecordSelfCheckInAction;
use App\Features\Registration\Actions\RegisterFreeAdmissionAction;
use App\Features\Registration\Api\AdmissionCheckout;
use App\Features\Registration\Api\AdmissionFacts;
use App\Features\Registration\Api\AdmissionIssuance;
use App\Features\Registration\Api\AdmissionRefunds;
use App\Features\Registration\Api\AdmissionRegistration;
use App\Features\Registration\Api\AttendanceCommands;
use App\Features\Registration\Api\AttendanceState;
use App\Features\Registration\Contributors\RegistrationAccountLifecycleContributor;
use App\Features\Registration\Listeners\CompletePendingRefundOnPaymentRefunded;
use App\Features\Registration\Queries\DatabaseAdmissionFacts;
use App\Features\Registration\Queries\ResolveAttendanceState;
use App\Platform\FeatureModule;
use App\Shared\Auth\AccountLifecycleContributor;
use Illuminate\Support\Facades\Event;

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
 * writes.
 *
 * R2b claims staff-assisted participation attendance. The staff check-in
 * action writes the event-scope aggregate directly and delegates
 * occurrence/session-scope rows to the package service; the update action
 * writes explicit attended/did-not-attend decisions, immutable logs and the
 * same-transaction check-in outcome reversal. Both are named writers for
 * event_attendances and event_attendance_logs. The staff participants
 * workspace stays a root transport adapter over the AttendanceCommands API.
 *
 * R3 claims organizer-issued offline and complimentary admissions.
 * IssueOfflineAdmissionAction owns the aggregate issuance entry point and is
 * the named app writer for event_registrations, participants, answers and
 * items; it delegates the actual rows to the orders/events packages.
 * ConfirmOfflineAdmissionAction owns pending-payment confirmation and writes
 * no registration rows itself (package fulfillment listeners may sync the
 * registration externally). Orders, payments, passes and seating stay
 * package-canonical and unclaimed.
 *
 * R4a1 claims free-checkout registration metadata.
 * RecordFreeCheckoutMetadataAction is the named writer for the `event_checkout`
 * batch/agreement metadata on event_registrations. The root checkout transport
 * keeps batch-id generation, the direct-free redirect, idempotency and the
 * paid checkout pipeline.
 *
 * R4a2 claims paid checkout preparation. PrepareAdmissionCheckoutAction
 * implements the AdmissionCheckout boundary: it resolves event/buyer through
 * morph aliases, revalidates the checkout start guards, builds the package
 * cart and checkout session, and freezes affiliate and share provenance for
 * the payment callback. Carts, checkout sessions and affiliate tables stay
 * package-canonical and unclaimed. The root checkout transport keeps the
 * Livewire page, provider payment handoff, redirects and results, and the
 * free-metadata path delegates unchanged to RecordFreeCheckoutMetadataAction.
 *
 * R4b1a claims admission refund orchestration. ProcessAdmissionRefundAction
 * implements the AdmissionRefunds boundary: it resolves event, registration
 * and actor through morph aliases, keeps the existing purchaser/organizer
 * authorization and refund-eligibility rules, resolves and validates the
 * paid order, creates or claims the package pending refund, submits it to the
 * active provider, and records the pending/completed/failed transitions with
 * the same provider metadata. The orders and checkout packages remain the
 * canonical writers for refund rows and money movement, so orders/refunds
 * stay unclaimed; the action writes no event_registrations row directly and
 * delegates the refund-pending admission transition to the package
 * RegistrationService. The public and dashboard refund pages are root
 * transport adapters over the scalar boundary.
 *
 * R4b1b claims asynchronous refund completion. The
 * CompletePendingRefundOnPaymentRefunded listener translates provider refund
 * callbacks into the shared Orders transition. It lives with the feature and
 * is registered explicitly in this provider because Laravel event discovery
 * only scans app/Listeners. Order refund rows and money movement remain
 * package-canonical and unclaimed.
 *
 * R5a claims account-lifecycle contributor ownership.
 * RegistrationAccountLifecycleContributor rewrites event_registrations
 * registrant links, event_attendances attendee links and event check-in
 * verifier links during account deletion/restoration, and recreates rows
 * captured by legacy snapshots. It is tagged once by this provider under the
 * shared AccountLifecycleContributor tag with the unchanged `events` key and
 * priority 70, so existing deleted-account snapshots stay readable. The
 * contributor is intentionally not a manifest writer: contributors are not
 * Actions/Persistence classes, and the account-lifecycle write spans tables
 * owned by different features. Its event ownership and event_submissions
 * submitter-link rewrites are account-lifecycle cross-owner writes recorded
 * for the final exact exceptions, while its event_registrations and
 * event_attendances link writes target tables owned by this feature.
 *
 * The following root/package writers remain and are recorded for the later
 * migration slices:
 *
 * - root Registration model: booted() repeats the vendor cascade and deletes
 *   check-in rows on a direct registration delete. The vendor cascade runs
 *   from the registration `deleting` hook and does remove participants,
 *   answers, items and attendances for direct deletes, but root keeps the
 *   hook until R6 verifies every delete path and the participant-only
 *   attendance edge.
 * - vendor Filament registration/attendance surfaces: the registration
 *   resource importer (EventRegistrationImporter) and the check-in console
 *   (CheckInConsole) write registrations and attendance outside the feature
 *   actions.
 * - package CreateRegistrationsFromOrderAction (invoked by
 *   IssueOfflineAdmissionAction) and IssueEventRegistrationPassesAction
 *   (invoked by the offline issuance/confirmation actions) remain the
 *   canonical writers for registration and pass rows created from orders.
 *
 * The dashboard registration-questions workspace is a Registration entry
 * point that delegates question writes to the package actions; admission
 * setup remains Events-owned. Pending slices: R5 reads/exports, R6 cleanup
 * and delete-path consolidation. Registration exports and the vendor Filament
 * attendance console also remain deferred to those slices.
 */
class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->bind(AdmissionFacts::class, DatabaseAdmissionFacts::class);
        $this->app->bind(AdmissionIssuance::class, IssueOfflineAdmissionAction::class);
        $this->app->bind(AdmissionRegistration::class, RegisterFreeAdmissionAction::class);
        $this->app->bind(AdmissionCheckout::class, PrepareAdmissionCheckoutAction::class);
        $this->app->bind(AdmissionRefunds::class, ProcessAdmissionRefundAction::class);
        $this->app->bind(AttendanceCommands::class, RecordSelfCheckInAction::class);
        $this->app->bind(AttendanceState::class, ResolveAttendanceState::class);

        $this->app->tag([
            RegistrationAccountLifecycleContributor::class,
        ], AccountLifecycleContributor::TAG);
    }

    public function boot(): void
    {
        parent::boot();

        // Event discovery only scans app/Listeners, so the feature registers
        // its provider-neutral refund callback listener explicitly.
        Event::listen(
            PaymentRefunded::class,
            [CompletePendingRefundOnPaymentRefunded::class, 'handle'],
        );
    }
}
