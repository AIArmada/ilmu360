<?php

use App\Enums\NotificationFamily;
use App\Enums\NotificationPriority;
use App\Enums\NotificationTrigger;
use App\Models\User;
use App\Notifications\InAppNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('writes to notification_inboxes when InApp notification is sent', function () {
    $notification = new InAppNotification(
        pendingNotificationId: (string) Str::uuid(),
        family: NotificationFamily::EventUpdates,
        trigger: NotificationTrigger::EventApproved,
        priority: NotificationPriority::Medium,
        title: 'Inbox Test Title',
        body: 'Inbox Test Body',
        occurredAt: now(),
        meta: [],
    );

    Notification::send($this->user, $notification);

    expect(DB::table('notification_inboxes')
        ->where('title', 'Inbox Test Title')
        ->where('body', 'Inbox Test Body')
        ->exists()
    )->toBeTrue();
});
