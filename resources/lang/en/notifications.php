<?php

return [
    'menu' => 'Notifications',
    'actions' => [
        'open' => 'Open notification',
    ],
    'flash' => [
        'updated' => 'Notification settings updated.',
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
        'email_pending' => 'Add and verify an email address to use email delivery.',
        'whatsapp_ready' => 'WhatsApp delivery is ready on your verified phone number.',
        'whatsapp_pending' => 'Verify your phone number before WhatsApp can be used.',
        'push_devices' => '{0} No devices connected|{1} 1 device connected|[2,*] :count devices connected',
        'push_ready' => 'Your signed-in devices can receive push notifications.',
        'push_pending' => 'Push delivery appears after you sign in from the mobile app.',
    ],
    'options' => [
        'cadence' => [
            'instant' => 'Instant',
            'daily' => 'Daily digest',
            'weekly' => 'Weekly digest',
            'off' => 'Off',
        ],
        'fallback' => [
            'next_available' => 'Try the next available channel',
            'in_app_only' => 'Keep it in-app only',
            'skip' => 'Skip external delivery',
        ],
        'weekdays' => [
            'monday' => 'Monday',
            'tuesday' => 'Tuesday',
            'wednesday' => 'Wednesday',
            'thursday' => 'Thursday',
            'friday' => 'Friday',
            'saturday' => 'Saturday',
            'sunday' => 'Sunday',
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
    ],
    'triggers' => [
        'followed_speaker_event' => [
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
    'ui' => [
        'tabs' => [
            'profile' => 'Profile',
            'notifications' => 'Notifications',
        ],
        'save' => 'Save Notification Settings',
        'delivery' => [
            'eyebrow' => 'Delivery Settings',
            'heading' => 'How you want to receive updates',
            'description' => 'Control delivery timing, quiet hours, fallback behaviour, and your preferred channel order.',
            'locale' => 'Notification language',
            'locale_help' => 'Use this locale when notifications are built for external delivery.',
            'timezone' => 'Delivery timezone',
            'timezone_help' => 'Digest scheduling and quiet hours follow the timezone in your profile settings.',
            'manage_timezone' => 'Manage timezone in profile',
            'quiet_hours_start' => 'Quiet hours start',
            'quiet_hours_end' => 'Quiet hours end',
            'quiet_hours_help' => 'Push and WhatsApp deliveries wait until quiet hours end unless the alert is urgent.',
            'quiet_hours_end_help' => 'Leave both quiet-hour fields empty if you do not want a delivery pause window.',
            'digest_delivery_time' => 'Digest delivery time',
            'digest_delivery_time_help' => 'Daily and weekly digests will be grouped around this local time.',
            'digest_weekly_day' => 'Weekly digest day',
            'digest_weekly_day_help' => 'Choose which day weekly digests should arrive.',
            'fallback_strategy' => 'Fallback behaviour',
            'fallback_strategy_help' => 'Decide what happens when your preferred external channel is unavailable.',
            'preferred_channels' => 'Preferred channel order',
            'preferred_channels_help' => 'Rank the channels you want tried first when more than one is enabled.',
            'channel_slot' => 'Preference :number',
            'no_preference' => 'No preference',
            'urgent_override' => 'Allow urgent alerts to bypass quiet hours',
        ],
        'destinations' => [
            'eyebrow' => 'Connected Destinations',
            'heading' => 'Where alerts can be delivered',
            'description' => 'Email comes from your account email, WhatsApp uses your verified phone number, and push destinations come from connected mobile apps.',
            'verified' => 'Verified',
            'needs_verification' => 'Needs verification',
            'connected' => 'Connected',
            'none_connected' => 'No device',
            'email_help' => 'Email is ready to receive notifications.',
            'add_email_help' => 'Add an email address in your profile if you want to receive email alerts.',
            'whatsapp_help' => 'WhatsApp becomes available because your phone number is verified.',
            'whatsapp_unverified_help' => 'Verify your phone number in your profile before enabling WhatsApp alerts.',
            'push_help' => 'Connect the mobile app on iPhone or Android to receive push notifications here.',
            'last_seen' => 'Last seen: :date',
            'push_devices_count' => '{0} No connected devices|{1} :count connected device|[2,*] :count connected devices',
        ],
        'families' => [
            'eyebrow' => 'Notification Families',
            'heading' => 'Choose what matters to you',
            'description' => 'Each family controls the default cadence and channels for a group of related alerts.',
            'enabled' => 'Enabled',
            'cadence' => 'Default cadence',
            'channels' => 'Default channels',
            'trigger_count' => '{1} :count trigger|[2,*] :count triggers',
        ],
        'triggers' => [
            'summary' => 'Customize specific alerts',
            'enabled' => 'Enabled',
            'use_family_defaults' => 'Use family defaults',
            'inherits_family_help' => 'This alert follows the family cadence and channels above until you customize it.',
            'cadence' => 'Override cadence',
            'channels' => 'Override channels',
            'urgent_override' => 'Let this alert bypass quiet hours when urgent',
        ],
    ],
    'pages' => [
        'settings' => [
            'tab' => 'Notifications',
            'heading' => 'Manage your account and notifications from one place.',
            'description' => 'Update your profile details, choose how notifications reach you, and review which channels are ready for delivery.',
            'delivery_heading' => 'Delivery settings',
            'delivery_description' => 'Choose when notifications can reach you, how digests are grouped, and which channels should be tried first.',
            'timezone_label' => 'Scheduling timezone',
            'language_label' => 'Notification language',
            'fallback_label' => 'If a preferred channel is unavailable',
            'digest_time_label' => 'Digest delivery time',
            'digest_day_label' => 'Weekly digest day',
            'quiet_hours_start_label' => 'Quiet hours start',
            'quiet_hours_end_label' => 'Quiet hours end',
            'preferred_channels_label' => 'Preferred delivery order',
            'preferred_channels_description' => 'Set the order in which channels should be attempted for instant notifications. Empty slots are ignored.',
            'fallback_channels_label' => 'Fallback channels',
            'fallback_channels_description' => 'Choose the backup channels to try when a preferred channel cannot deliver.',
            'channel_slot_label' => 'Choice :number',
            'skip_channel_option' => 'Do not use this slot',
            'urgent_override_label' => 'Let urgent notifications bypass quiet hours',
            'urgent_override_description' => 'Urgent alerts such as same-day cancellations, 2-hour reminders, and check-in opening can still go out during quiet hours.',
            'families_heading' => 'Notification categories',
            'families_description' => 'Control each notification family first, then fine-tune the specific alerts inside it.',
            'cadence_label' => 'Delivery cadence',
            'channels_label' => 'Channels',
            'trigger_heading' => 'Trigger-level overrides',
            'trigger_description' => 'Use these when one alert in the category should behave differently from the rest.',
            'footer_note' => 'Push devices are managed from signed-in mobile apps. Email uses your account email, and WhatsApp uses your verified phone number.',
            'save_button' => 'Save Notification Settings',
        ],
        'inbox' => [
            'nav_label' => 'Notifications',
            'cta' => 'Open inbox',
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
        'empty' => [
            'heading' => 'Nothing to review yet',
            'description' => 'New alerts will appear here once something you follow or track needs your attention.',
        ],
    ],
];
