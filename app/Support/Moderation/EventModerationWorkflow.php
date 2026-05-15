<?php

declare(strict_types=1);

namespace App\Support\Moderation;

use App\Models\Event;
use App\States\EventStatus\Approved;
use App\States\EventStatus\Cancelled;
use App\States\EventStatus\Draft;
use App\States\EventStatus\NeedsChanges;
use App\States\EventStatus\Pending;
use App\States\EventStatus\Rejected;

final class EventModerationWorkflow
{
    /**
     * @return list<string>
     */
    public static function allActionKeys(): array
    {
        return [
            'submit_for_moderation',
            'approve',
            'request_changes',
            'reject',
            'cancel',
            'reconsider',
            'remoderate',
            'revert_to_draft',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function reasonOptions(): array
    {
        return [
            'incomplete_info' => 'Incomplete Information',
            'duplicate' => 'Duplicate Event',
            'inappropriate' => 'Inappropriate Content',
            'spam' => 'Spam',
            'wrong_category' => 'Wrong Category',
            'inaccurate_details' => 'Inaccurate Details',
            'missing_speaker' => 'Missing Speaker Information',
            'missing_venue' => 'Missing Venue Information',
            'other' => 'Other',
        ];
    }

    /**
     * @return array<string, array{label: string, description: string, requires_reason_code: bool, requires_note: bool}>
     */
    public static function availableActions(Event $event): array
    {
        $actions = [];

        if ($event->status instanceof Draft) {
            $actions['submit_for_moderation'] = self::definition(
                label: 'Submit for Moderation',
                description: 'Move this draft event to pending so moderators can review it.',
            );
        }

        if ($event->status instanceof Pending) {
            $actions['approve'] = self::definition(
                label: 'Approve',
                description: 'Publish this pending event and make it searchable.',
            );
            $actions['request_changes'] = self::definition(
                label: 'Request Changes',
                description: 'Move this pending event to needs-changes and notify the submitter what to fix.',
                requiresReasonCode: true,
                requiresNote: true,
            );
            $actions['reject'] = self::definition(
                label: 'Reject',
                description: 'Reject this pending event and remove it from search.',
                requiresReasonCode: true,
                requiresNote: true,
            );
            $actions['cancel'] = self::definition(
                label: 'Cancel Event',
                description: 'Cancel this event while keeping the public record visible with a cancelled badge.',
            );
        }

        if ($event->status instanceof Approved) {
            $actions['cancel'] = self::definition(
                label: 'Cancel Event',
                description: 'Cancel this approved event and notify affected users.',
            );
            $actions['remoderate'] = self::definition(
                label: 'Send for Re-moderation',
                description: 'Move this approved event back to pending for re-review.',
            );
        }

        if ($event->status instanceof Rejected) {
            $actions['reconsider'] = self::definition(
                label: 'Reconsider',
                description: 'Move this rejected event back to pending for another review pass.',
            );
            $actions['revert_to_draft'] = self::definition(
                label: 'Revert to Draft',
                description: 'Move this rejected event back to draft for rework.',
            );
        }

        if ($event->status instanceof NeedsChanges) {
            $actions['revert_to_draft'] = self::definition(
                label: 'Revert to Draft',
                description: 'Move this needs-changes event back to draft for rework.',
            );
        }

        if ($event->status instanceof Cancelled) {
            $actions['remoderate'] = self::definition(
                label: 'Reopen for Review',
                description: 'Move this cancelled event back to pending for moderation review.',
            );
            $actions['revert_to_draft'] = self::definition(
                label: 'Revert to Draft',
                description: 'Move this cancelled event back to draft.',
            );
        }

        return $actions;
    }

    /**
     * @return array{label: string, description: string, requires_reason_code: bool, requires_note: bool}
     */
    private static function definition(
        string $label,
        string $description,
        bool $requiresReasonCode = false,
        bool $requiresNote = false,
    ): array {
        return [
            'label' => $label,
            'description' => $description,
            'requires_reason_code' => $requiresReasonCode,
            'requires_note' => $requiresNote,
        ];
    }
}
