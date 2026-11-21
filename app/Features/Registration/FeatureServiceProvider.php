<?php

declare(strict_types=1);

namespace App\Features\Registration;

use AIArmada\CommerceSupport\Events\PaymentRefunded;
use App\Features\Registration\Actions\DeleteEventRegistrationsAction;
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
use App\Features\Registration\Api\EventRegistrationDeletion;
use App\Features\Registration\Api\Orm\RegistrationLookup;
use App\Features\Registration\Api\PaidRegistrationFacts;
use App\Features\Registration\Api\ParticipationSourceIds;
use App\Features\Registration\Contributors\RegistrationAccountLifecycleContributor;
use App\Features\Registration\Listeners\CompletePendingRefundOnPaymentRefunded;
use App\Features\Registration\Queries\DatabaseAdmissionFacts;
use App\Features\Registration\Queries\DatabasePaidRegistrationFacts;
use App\Features\Registration\Queries\DatabaseParticipationSourceIds;
use App\Features\Registration\Queries\DatabaseRegistrationLookup;
use App\Features\Registration\Queries\ResolveAttendanceState;
use App\Platform\FeatureModule;
use App\Shared\Auth\AccountLifecycleContributor;
use Illuminate\Support\Facades\Event;

/**
 * Registration owns event admission, attendance and check-in history.
 * App-owned Actions control state writes and delegate package operations
 * where required; the account lifecycle adapter preserves historical facts
 * while an owning Action detaches/restores personal identity links.
 */
class FeatureServiceProvider extends FeatureModule
{
    public function register(): void
    {
        $this->app->bind(RegistrationLookup::class, DatabaseRegistrationLookup::class);
        $this->app->bind(AdmissionFacts::class, DatabaseAdmissionFacts::class);
        $this->app->bind(PaidRegistrationFacts::class, DatabasePaidRegistrationFacts::class);
        $this->app->bind(ParticipationSourceIds::class, DatabaseParticipationSourceIds::class);
        $this->app->bind(AdmissionIssuance::class, IssueOfflineAdmissionAction::class);
        $this->app->bind(AdmissionRegistration::class, RegisterFreeAdmissionAction::class);
        $this->app->bind(AdmissionCheckout::class, PrepareAdmissionCheckoutAction::class);
        $this->app->bind(AdmissionRefunds::class, ProcessAdmissionRefundAction::class);
        $this->app->bind(AttendanceCommands::class, RecordSelfCheckInAction::class);
        $this->app->bind(AttendanceState::class, ResolveAttendanceState::class);
        $this->app->bind(EventRegistrationDeletion::class, DeleteEventRegistrationsAction::class);

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
