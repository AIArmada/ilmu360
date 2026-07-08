<?php

declare(strict_types=1);

namespace App\Support\Membership;

use AIArmada\Membership\Contracts\MembershipApplicationNotifier;
use AIArmada\Membership\Models\MembershipApplication;

class AppMembershipApplicationNotifier implements MembershipApplicationNotifier
{
    public function notifySubmitted(MembershipApplication $application): void
    {
        // ponytail: basic no-op for now; add NotificationCenterMessage dispatch when admin notification routing is wired
    }

    public function notifyApproved(MembershipApplication $application): void
    {
        // ponytail: basic no-op for now; add applicant notification when NotificationEngine integration is complete
    }

    public function notifyRejected(MembershipApplication $application): void
    {
        // ponytail: basic no-op for now; add applicant notification when NotificationEngine integration is complete
    }
}
