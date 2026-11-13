<?php

use AIArmada\Communications\Enums\NotificationFamily as PackageNotificationFamily;
use App\Features\Communications\Api\InAppNotificationPayload;
use App\Features\Communications\Api\NotificationFamily;
use App\Features\Communications\Api\NotificationPriority;
use App\Features\Communications\Api\NotificationTrigger;
use App\Features\Communications\Notifications\InAppNotification;
use App\Features\Communications\Support\Normalization\EnumMapper;
use App\Features\Communications\Support\Normalization\NotificationInboxPresentation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('writes to notification_inboxes when InApp notification is sent', function () {
    $notification = new InAppNotification(new InAppNotificationPayload(
        pendingNotificationId: (string) Str::uuid(),
        family: NotificationFamily::EventUpdates,
        trigger: NotificationTrigger::EventApproved,
        priority: NotificationPriority::Medium,
        title: 'Inbox Test Title',
        body: 'Inbox Test Body',
        occurredAt: now()->toIso8601String(),
        meta: [],
    ));

    Notification::send($this->user, $notification);

    expect(DB::table('notification_inboxes')
        ->where('title', 'Inbox Test Title')
        ->where('body', 'Inbox Test Body')
        ->exists()
    )->toBeTrue();
});

it('stores sharing notifications in the weekly digest family and maps them back to app policy', function () {
    $notification = new InAppNotification(new InAppNotificationPayload(
        pendingNotificationId: (string) Str::uuid(),
        family: NotificationFamily::Sharing,
        trigger: NotificationTrigger::ShareGratitudeDigest,
        priority: NotificationPriority::Low,
        title: 'Sharing Inbox Test Title',
        body: 'Sharing Inbox Test Body',
    ));

    Notification::send($this->user, $notification);

    $family = DB::table('notification_inboxes')
        ->where('title', 'Sharing Inbox Test Title')
        ->value('family');

    expect($family)
        ->toBe(PackageNotificationFamily::DigestWeekly->value)
        ->and(EnumMapper::toPkgFamily(NotificationFamily::Sharing))
        ->toBe(PackageNotificationFamily::DigestWeekly)
        ->and(NotificationInboxPresentation::storedFamilyValue(NotificationFamily::Sharing->value))
        ->toBe(PackageNotificationFamily::DigestWeekly->value)
        ->and(NotificationInboxPresentation::appFamily($family))
        ->toBe(NotificationFamily::Sharing);
});
