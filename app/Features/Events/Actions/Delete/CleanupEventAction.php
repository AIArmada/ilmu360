<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Delete;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Features\Events\Api\EventDeletion;
use App\Features\Events\Support\EventModel;
use App\Features\Membership\Api\MembershipCommands;
use App\Shared\Membership\MembershipSubjectType;

/**
 * Owns the application-level cleanup that runs before the event row is
 * deleted.
 *
 * Direct deletes here: membership pivots (through the Membership boundary),
 * event involvements/key people, access policies, reference records,
 * bookmarks, going responses, registrations, check-ins, submissions,
 * moderation reviews, change announcements and media links. The event-scoped
 * package tables without app write paths (links, audiences, languages,
 * locations, classifications, time expressions, series items) are still
 * removed by the vendor EventDeleteCascade in the model's `deleted` hook.
 */
final readonly class CleanupEventAction implements EventDeletion
{
    public function __construct(
        private MembershipCommands $membership,
    ) {}

    public function cleanup(string $eventId): void
    {
        $event = EventModel::query('event')->withoutOwnerScope()->find($eventId);

        if ($event === null) {
            return;
        }

        $this->membership->removeAllMembers(
            MembershipSubjectType::Event,
            $eventId,
        );

        $event->involvements()->delete();
        $event->accessPolicies()->delete();
        $event->keyPeople()->delete();
        $event->referenceRecords()->delete();
        $event->savedBy()->delete();
        $event->goingBy()->delete();

        $event->registrations()->each(static function ($registration): void {
            $registration->delete();
        });
        $event->checkins()->each(static function ($checkin): void {
            $checkin->delete();
        });
        $event->submissions()->each(static function ($submission): void {
            $submission->delete();
        });
        $event->moderationReviews()->each(static function ($review): void {
            OwnerContext::withOwner(null, static fn () => $review->delete());
        });
        $event->changeAnnouncements()->each(static function ($announcement): void {
            $announcement->delete();
        });
        $event->mediaLinks()->each(static function ($mediaLink): void {
            $mediaLink->delete();
        });
    }
}
