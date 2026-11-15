<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Delete;

use App\Features\Engagement\Api\EventEngagementDeletion;
use App\Features\Events\Api\EventDeletion;
use App\Features\Events\Api\EventModeration;
use App\Features\Events\Support\EventModel;
use App\Features\Membership\Api\MembershipCommands;
use App\Features\Registration\Api\EventRegistrationDeletion;
use App\Shared\Membership\MembershipSubjectType;

/**
 * Owns the application-level cleanup that runs before the event row is
 * deleted.
 *
 * Cleanup here includes membership pivots (through the Membership boundary),
 * event involvements/key people, access policies, reference records,
 * submissions, moderation reviews, change announcements and media links.
 * Engagement and Registration cleanup delegate to their owning features before
 * the event row is deleted. The vendor EventDeleteCascade runs afterward in the
 * model's `deleting` hook while the event is still available to its owner-scoped
 * child queries, so it removes the remaining package-owned children.
 * The event-scoped package tables without app write paths (links, audiences,
 * languages, locations, classifications, time expressions, series items) are
 * still removed by that vendor cascade.
 */
final readonly class CleanupEventAction implements EventDeletion
{
    public function __construct(
        private MembershipCommands $membership,
        private EventEngagementDeletion $engagementDeletion,
        private EventRegistrationDeletion $registrationDeletion,
        private EventModeration $eventModeration,
    ) {}

    public function cleanup(string $eventId): void
    {
        $event = EventModel::query('event')->withoutOwnerScope()->find($eventId);

        if ($event === null) {
            return;
        }

        $this->registrationDeletion->assertEventRegistrationsCanBeDeleted($eventId);

        $this->membership->removeAllMembers(
            MembershipSubjectType::Event,
            $eventId,
        );

        $event->involvements()->delete();
        $event->accessPolicies()->delete();
        $event->keyPeople()->delete();
        $event->referenceRecords()->delete();
        $this->engagementDeletion->deleteForEvent($eventId, $event->getMorphClass());

        $this->registrationDeletion->deleteForEvent($eventId);

        $event->submissions()->each(static function ($submission): void {
            $submission->delete();
        });
        $this->eventModeration->deleteReviewsForEvent($eventId);
        $event->changeAnnouncements()->each(static function ($announcement): void {
            $announcement->delete();
        });
        $event->mediaLinks()->each(static function ($mediaLink): void {
            $mediaLink->delete();
        });
    }
}
