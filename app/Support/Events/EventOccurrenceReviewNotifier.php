<?php

declare(strict_types=1);

namespace App\Support\Events;

use AIArmada\Contacting\Enums\ContactMethodType;
use AIArmada\Events\Models\EventOccurrence;
use App\Models\Event;
use App\Models\EventSubmission;
use App\Models\User;
use App\Platform\Notifications\Events\EventOccurrenceReviewedNotification;
use App\Shared\Events\OccurrenceReviewNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Root notification adapter: Events owns moderation while this integration
 * resolves Laravel notifiables and the existing queued notification.
 */
final readonly class EventOccurrenceReviewNotifier implements OccurrenceReviewNotifier
{
    public function notify(
        string $submissionId,
        string $eventId,
        string $occurrenceId,
        bool $approved,
        ?string $reason,
        ?string $note,
    ): void {
        $submission = EventSubmission::query()->findOrFail($submissionId);
        $event = Event::query()->findOrFail($eventId);
        $occurrence = EventOccurrence::query()->findOrFail($occurrenceId);
        $notification = new EventOccurrenceReviewedNotification($event, $occurrence, $approved, $reason, $note);

        DB::afterCommit(function () use ($submission, $notification): void {
            $submitter = $submission->submitter;

            if ($submitter instanceof User) {
                $submitter->notify($notification);

                return;
            }

            $email = $submission->contactMethods()
                ->where('type', ContactMethodType::Email->value)
                ->value('value');

            if (is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                Notification::route('mail', $email)->notify($notification);
            }
        });
    }
}
