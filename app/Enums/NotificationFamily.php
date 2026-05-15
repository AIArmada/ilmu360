<?php

declare(strict_types=1);

namespace App\Enums;

enum NotificationFamily: string
{
    case FollowedContent = 'followed_content';
    case SavedSearchMatches = 'saved_search_matches';
    case EventUpdates = 'event_updates';
    case EventReminders = 'event_reminders';
    case RegistrationCheckin = 'registration_checkin';
    case SubmissionWorkflow = 'submission_workflow';
}
