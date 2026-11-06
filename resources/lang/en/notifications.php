<?php

return [
    'menu' => 'Notifications',
    'actions' => [
        'open' => 'Open notification',
    ],
    'flash' => [
        'updated' => 'Notification settings updated.',
        'device_removed' => 'Device removed.',
    ],
    'api' => [
        'read_success' => 'Notification marked as read.',
        'read_all_success' => 'Notifications marked as read.',
        'push_registered' => 'Push device connected.',
        'push_updated' => 'Push device updated.',
    ],
    'mail' => [
        'greeting' => 'Hello :name,',
        'generic_recipient' => 'there',
        'occurred_at' => 'Occurred at: :datetime',
        'footer' => 'You are receiving this because your notification settings allow this update.',
    ],
    'auth' => [
        'actions' => [
            'open_dashboard' => 'Open dashboard',
            'reset_password' => 'Reset password',
            'verify_email' => 'Verify email address',
        ],
        'verification' => [
            'subject' => 'Verify your email address',
            'intro' => 'Please verify your email address to finish setting up your account.',
            'outro' => 'If you did not create an account, no further action is required.',
        ],
        'welcome' => [
            'subject' => 'Welcome to :app',
            'intro' => 'Welcome to :app.',
            'body' => 'Your account is ready. You can now save events, follow speakers or institutions, and manage your own submissions.',
            'verify_hint' => 'Please verify your email address so email-based notifications and account recovery stay available.',
            'footer' => 'Thank you for joining :app.',
        ],
        'reset_password' => [
            'subject' => 'Reset your password',
            'intro' => 'We received a request to reset the password for your account.',
            'expiry' => 'This password reset link will expire in :count minutes.',
            'outro' => 'If you did not request a password reset, no further action is required.',
        ],
    ],
    'membership' => [
        'invitation' => [
            'subject' => ':inviter invited you to join :subject',
            'intro' => ':inviter invited you to join this :subject_label as :role.',
            'subject_name' => 'Subject: :name',
            'role' => 'Role: :role',
            'expires' => 'Expires: :datetime',
            'action' => 'Review invitation',
            'footer' => 'Sign in with :email to review and accept this invitation.',
        ],
    ],
    'moderation' => [
        'greeting' => 'Assalamualaikum,',
        'not_scheduled' => 'Not scheduled yet',
        'actions' => [
            'review_event' => 'Review event',
        ],
        'fields' => [
            'institution' => 'Institution: :name',
            'event_datetime' => 'Event time: :datetime',
        ],
        'submitted' => [
            'subject' => 'New event submitted: :title',
            'intro' => 'A new event has been submitted and needs moderation.',
            'public_submission' => 'Public submission',
            'footer' => 'Please review this submission as soon as possible.',
        ],
        'escalation' => [
            'subjects' => [
                '48_hours' => 'Event pending review over 48 hours: :title',
                '72_hours' => 'Urgent escalation for pending event: :title',
                'urgent' => 'Time-sensitive pending event: :title',
                'priority' => 'Priority pending event: :title',
            ],
            'greetings' => [
                '48_hours' => 'Moderation SLA alert,',
                '72_hours' => 'Urgent escalation,',
                'urgent' => 'Time-sensitive event alert,',
                'priority' => 'Priority event alert,',
            ],
            'messages' => [
                '48_hours' => 'This event has been pending moderation for more than 48 hours.',
                '72_hours' => 'This event has been pending moderation for more than 72 hours and needs immediate attention.',
                'urgent' => 'This event is still pending moderation and starts within the next 24 hours.',
                'priority' => 'This event is still pending moderation and starts within the next 6 hours.',
            ],
            'urgent_footer' => 'Please review this event soon so the status is resolved before it starts.',
            'priority_footer' => 'This event needs immediate action because it is very close to starting.',
        ],
    ],
    'reports' => [
        'resolved' => [
            'subject_resolved' => 'Your report has been resolved',
            'subject_dismissed' => 'Your report has been closed',
            'intro' => 'Your report about this :entity has been reviewed by our moderation team.',
            'status' => 'Status: :status',
            'note' => 'Moderator note: :note',
            'footer' => 'Thank you for helping us keep :app useful and accurate.',
            'action' => 'View reported subject',
            'statuses' => [
                'resolved' => 'Resolved',
                'dismissed' => 'Dismissed',
            ],
        ],
    ],
    'destinations' => [
        'unknown_device' => 'Unknown device',
        'not_available' => 'Not available',
        'email_ready' => 'Email is ready to receive notifications.',
        'push_devices' => '{0} No devices connected|{1} 1 device connected|[2,*] :count devices connected',
    ],
    'options' => [
        'cadence' => [
            'instant' => 'Instant',
            'daily' => 'Daily digest',
            'weekly' => 'Weekly digest',
            'off' => 'Off',
        ],
        'priority' => [
            'low' => 'Low',
            'medium' => 'Medium',
            'high' => 'High',
            'urgent' => 'Urgent',
        ],
    ],
    'families' => [
        'followed_content' => [
            'label' => 'Followed Content',
            'description' => 'New future events that match speakers, institutions, series, or references you follow.',
        ],
        'saved_search_matches' => [
            'label' => 'Saved Search Matches',
            'description' => 'Fresh matches for your saved searches when new public events are published.',
        ],
        'event_updates' => [
            'label' => 'Event Updates',
            'description' => 'Important changes to events you saved, planned to attend, or registered for.',
        ],
        'event_reminders' => [
            'label' => 'Event Reminders',
            'description' => 'Time-based reminders for events you are going to or already registered for.',
        ],
        'registration_checkin' => [
            'label' => 'Registration & Check-in',
            'description' => 'Confirmation and follow-up messages for registrations and event check-ins.',
        ],
        'submission_workflow' => [
            'label' => 'Submission Workflow',
            'description' => 'Moderation updates for events and directory entries you submitted or help manage.',
        ],
        'sharing' => [
            'label' => 'Sharing',
            'description' => 'Delayed gratitude for the impact of shares you made.',
        ],
    ],
    'triggers' => [
        'followed_person_event' => [
            'label' => 'New event from a followed speaker',
            'description' => 'Alert me when a future public event is linked to a speaker I follow.',
        ],
        'followed_institution_event' => [
            'label' => 'New event from a followed institution',
            'description' => 'Alert me when a future public event is linked to an institution I follow.',
        ],
        'followed_series_event' => [
            'label' => 'New event from a followed series',
            'description' => 'Alert me when a future public event is linked to a series I follow.',
        ],
        'followed_reference_event' => [
            'label' => 'New event from a followed reference',
            'description' => 'Alert me when a future public event is linked to a reference I follow.',
        ],
        'saved_search_match' => [
            'label' => 'Saved search match',
            'description' => 'Alert me when a newly published event matches one of my saved searches.',
        ],
        'event_approved' => [
            'label' => 'Saved or tracked event approved',
            'description' => 'Alert me when a tracked event moves from review into an approved public state.',
        ],
        'event_cancelled' => [
            'label' => 'Event cancelled',
            'description' => 'Send an urgent alert when a tracked event is cancelled.',
        ],
        'event_schedule_changed' => [
            'label' => 'Event schedule changed',
            'description' => 'Alert me when the timing or title of a tracked event changes in a meaningful way.',
        ],
        'event_venue_changed' => [
            'label' => 'Event venue changed',
            'description' => 'Alert me when a tracked event moves venue or space.',
        ],
        'event_details_changed' => [
            'label' => 'Event details changed',
            'description' => 'Alert me when a tracked event has a speaker, topic, reference, organizer, or other important detail change.',
        ],
        'event_replacement_linked' => [
            'label' => 'Replacement event linked',
            'description' => 'Alert me when a tracked event points to a replacement event.',
        ],
        'reminder_24_hours' => [
            'label' => '24-hour reminder',
            'description' => 'Send a reminder about going or registered events a day before they start.',
        ],
        'reminder_2_hours' => [
            'label' => '2-hour reminder',
            'description' => 'Send a final reminder shortly before the event starts.',
        ],
        'checkin_open' => [
            'label' => 'Check-in is open',
            'description' => 'Alert me when self check-in becomes available.',
        ],
        'registration_confirmed' => [
            'label' => 'Registration confirmed',
            'description' => 'Confirm successful event registration immediately.',
        ],
        'registration_event_changed' => [
            'label' => 'Registered event changed',
            'description' => 'Alert me when an event I registered for changes in a material way.',
        ],
        'checkin_confirmed' => [
            'label' => 'Check-in confirmed',
            'description' => 'Confirm that event check-in succeeded.',
        ],
        'submission_received' => [
            'label' => 'Submission received',
            'description' => 'Confirm that a submission entered the moderation flow.',
        ],
        'submission_approved' => [
            'label' => 'Submission approved',
            'description' => 'Alert me when one of my submitted items is approved.',
        ],
        'submission_rejected' => [
            'label' => 'Submission rejected',
            'description' => 'Alert me when one of my submitted items is rejected.',
        ],
        'submission_needs_changes' => [
            'label' => 'Submission needs changes',
            'description' => 'Alert me when a moderator asks for revisions.',
        ],
        'submission_cancelled' => [
            'label' => 'Submission cancelled',
            'description' => 'Alert me when a submitted event is cancelled.',
        ],
        'submission_remoderated' => [
            'label' => 'Submission sent for review again',
            'description' => 'Alert me when a submitted event goes back into moderation.',
        ],
        'share_gratitude_digest' => [
            'label' => 'Sharing gratitude digest',
            'description' => 'A calm delayed thank-you digest for the impact of shares I made. No per-visit pings.',
        ],
    ],
    'messages' => [
        'followed_content' => [
            'title' => ':title matches something you follow',
            'body' => 'This event matches: :matches. :timing.',
        ],
        'saved_search_match' => [
            'title' => ':title matches your saved search',
            'body' => 'Matched searches: :searches.',
        ],
        'event_approved' => [
            'title' => ':title is now approved',
            'body' => 'The event is now public. :timing.',
        ],
        'event_cancelled' => [
            'title' => ':title has been cancelled',
            'body' => 'This event is no longer happening. :timing.',
            'body_with_note' => 'This event is no longer happening. :timing. Note: :note',
        ],
        'event_update' => [
            'title' => ':title has been updated',
        ],
        'event_change' => [
            'body' => ':summary',
        ],
        'event_schedule_changed' => [
            'body' => 'The schedule changed. :timing.',
        ],
        'event_venue_changed' => [
            'body' => 'The venue or space changed. :timing.',
        ],
        'event_details_changed' => [
            'body' => ':summary',
        ],
        'event_replacement_linked' => [
            'body' => ':summary',
        ],
        'registration_confirmed' => [
            'title' => 'Registration confirmed for :title',
            'body' => 'You are registered. :timing.',
        ],
        'registration_event_changed' => [
            'title' => ':title changed after you registered',
            'body' => 'A registered event changed. :timing.',
        ],
        'checkin_confirmed' => [
            'title' => 'Check-in confirmed for :title',
            'body' => 'Your check-in was recorded successfully.',
        ],
        'submission_received' => [
            'title' => 'Submission received: :title',
            'body' => 'The event is now waiting for moderation.',
        ],
        'submission_approved' => [
            'title' => 'Submission approved: :title',
            'body' => 'Your event is approved and visible to the public. :timing.',
        ],
        'submission_rejected' => [
            'title' => 'Submission rejected: :title',
            'body' => 'This submission was rejected.',
            'body_with_note' => 'This submission was rejected. Note: :note',
        ],
        'directory_submission_approved' => [
            'title' => ':subject approved: :title',
            'body' => 'Your submission is approved and now visible to the public.',
        ],
        'directory_submission_rejected' => [
            'title' => ':subject rejected: :title',
            'body' => 'This submission was rejected.',
            'body_with_note' => 'This submission was rejected. Note: :note',
        ],
        'submission_needs_changes' => [
            'title' => 'Changes requested for :title',
            'body' => 'A moderator asked you to revise this submission.',
            'body_with_note' => 'A moderator asked you to revise this submission. Note: :note',
        ],
        'submission_cancelled' => [
            'title' => 'Submission cancelled: :title',
            'body' => 'This submitted event has been cancelled.',
            'body_with_note' => 'This submitted event has been cancelled. Note: :note',
        ],
        'submission_remoderated' => [
            'title' => 'Submission reviewed again: :title',
            'body' => 'This submission is back in moderation.',
            'body_with_note' => 'This submission is back in moderation. Note: :note',
        ],
        'share_gratitude_digest' => [
            'title' => 'Thank you for sharing',
            'body' => 'Your recent shares are making a difference. :summary.',
        ],
        'reminder_24_hours' => [
            'title' => ':title starts tomorrow',
            'body' => 'Reminder for your upcoming event. :timing.',
        ],
        'reminder_2_hours' => [
            'title' => ':title starts soon',
            'body' => 'Your event begins in about two hours. :timing.',
        ],
        'checkin_open' => [
            'title' => 'Check-in is open for :title',
            'body' => 'You can now check in for this event.',
        ],
        'digest' => [
            'title' => ':count updates ready for review',
            'body' => 'Open your notifications to review the latest updates.',
        ],
    ],
    'pages' => [
        'settings' => [
            'tab' => 'Notifications',
            'heading' => 'Notifications',
            'description' => 'Choose what you want to hear about. We will handle how it reaches you.',
            'mandatory_heading' => 'Mandatory messages',
            'mandatory_body' => 'Cancellations, time or venue changes, and registration receipts for events you joined are always sent.',
            'channels_heading' => 'Channels',
            'channels_description' => 'Email and push. In-app is always on for mandatory messages.',
            'channel_in_app' => 'In-app',
            'channel_in_app_help' => 'Your inbox. Always on for mandatory messages.',
            'channel_in_app_status' => 'Always on',
            'channel_email' => 'Email',
            'channel_push' => 'Push',
            'manage_devices' => 'Manage devices',
            'channel_unavailable' => 'Verify in profile',
            'channel_push_unavailable' => 'Coming soon — the mobile app is still in development.',
            'groups_heading' => 'What you receive',
            'groups_description' => 'Turn on what is useful. Turn off what is not.',
            'discovery_heading' => 'New events for you',
            'discovery_description' => 'New events from speakers, institutions, series and searches you follow.',
            'cadence_label' => 'Frequency',
            'my_events_heading' => 'Events I joined',
            'my_events_description' => 'Reminders and check-in for events you registered for. Important changes are sent as mandatory messages.',
            'submission_heading' => 'Event submissions',
            'submission_description' => 'Review status for events you submitted. Only shown if you have submitted before.',
            'organizer_badge' => 'Organizer',
            'quiet_hours_heading' => 'Quiet hours',
            'quiet_hours_description' => 'We hold ordinary notifications. Mandatory messages are still sent.',
            'quiet_hours_label' => 'Do not disturb',
            'quiet_hours_help' => 'Uses the timezone in your profile.',
            'quiet_hours_start' => 'Start',
            'quiet_hours_end' => 'End',
            'devices_heading' => 'Devices',
            'devices_description' => 'Devices that receive push. Remove devices you no longer use.',
            'device_last_seen' => 'Active :time',
            'device_remove' => 'Remove',
            'device_remove_confirm' => 'Remove this device from push notifications?',
            'devices_empty' => 'No devices connected.',
            'save_button' => 'Save changes',
            'footer_note' => 'Push is delivered to the mobile app through the operating system.',
        ],
        'inbox' => [
            'nav_label' => 'Notifications',
            'cta' => 'Open inbox',
        ],
    ],
    'app_feedback' => [
        'received' => [
            'subject' => 'Feedback received: :title',
            'intro' => 'We have received your feedback. Our team will review it.',
            'reference' => 'Reference: :id',
            'status' => 'Current status: :status',
            'action' => 'View feedback',
            'footer' => 'Thank you for helping us improve :app.',
        ],
        'submitted' => [
            'subject' => 'New application feedback: :title',
            'intro' => 'New application feedback needs review.',
            'category' => 'Category: :category',
            'action' => 'Open in the admin panel',
            'footer' => 'Review this feedback when you can.',
        ],
        'closed' => [
            'subject_resolved' => 'Your feedback was resolved: :title',
            'subject_declined' => 'Your feedback was closed: :title',
            'intro' => 'Your feedback has been reviewed by our team.',
            'status' => 'Status: :status',
            'note' => 'Note: :note',
            'action' => 'View feedback',
            'footer' => 'Thank you for helping us improve :app.',
        ],
        'triaged' => [
            'subject' => 'Your feedback is under review: :title',
            'intro' => 'Our team has started reviewing your feedback. We will let you know once it is resolved.',
            'status' => 'Current status: :status',
            'action' => 'View feedback',
            'footer' => 'Thank you for helping us improve :app.',
        ],
    ],
    'inbox' => [
        'page_title' => 'Notifications',
        'eyebrow' => 'Notification Inbox',
        'heading' => 'Everything sent to you',
        'description' => 'Review unread alerts, open the related event or workflow, and keep track of what has already been seen.',
        'unread_count' => '{0} No unread notifications|{1} :count unread notification|[2,*] :count unread notifications',
        'manage_settings' => 'Manage notification settings',
        'mark_all_read' => 'Mark all as read',
        'family_filter' => 'Family',
        'all_families' => 'All families',
        'status_filter' => 'Status',
        'status' => [
            'unread' => 'Unread',
            'read' => 'Read',
            'all' => 'All',
        ],
        'channels_attempted' => 'Channels',
        'mark_read' => 'Mark as read',
        'open_link' => 'Open',
        'pagination' => [
            'label' => 'Notification pages',
            'summary' => 'Showing :from–:to of :total notifications',
            'previous' => 'Previous',
            'next' => 'Next',
        ],
        'empty' => [
            'heading' => 'Nothing to review yet',
            'description' => 'New alerts will appear here once something you follow or track needs your attention.',
            'cta' => 'Browse events',
            'view_all' => 'View all notifications',
        ],
    ],
    'bell' => [
        'label' => 'Notifications',
        'unread_aria' => 'Notifications, :count unread',
        'heading' => 'Notifications',
        'menu_label' => 'Notification options',
        'mark_all_read' => 'Mark all as read',
        'settings' => 'Notification settings',
        'open' => 'Open notifications',
        'tabs' => [
            'label' => 'Notification filters',
            'all' => 'All',
            'unread' => 'Unread',
        ],
        'today' => 'Today',
        'earlier' => 'Earlier',
        'see_all' => 'See all notifications',
        'empty' => [
            'heading' => 'No notifications yet',
            'description' => 'Updates about events, submissions, and things you follow will appear here.',
        ],
        'empty_unread' => [
            'heading' => 'You\'re all caught up',
            'description' => 'You have no unread notifications.',
        ],
        'loading' => 'Loading notifications...',
        'load_failed' => 'Could not load notifications.',
        'retry' => 'Try again',
        'unread_dot' => 'Unread',
    ],
];
