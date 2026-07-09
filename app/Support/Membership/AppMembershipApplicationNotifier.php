<?php

declare(strict_types=1);

namespace App\Support\Membership;

use AIArmada\Membership\Contracts\MembershipApplicationNotifier;
use AIArmada\Membership\Models\MembershipApplication;

class AppMembershipApplicationNotifier implements MembershipApplicationNotifier
{
    public function notifySubmitted(MembershipApplication $application): void {}

    public function notifyApproved(MembershipApplication $application): void {}

    public function notifyRejected(MembershipApplication $application): void {}
}
