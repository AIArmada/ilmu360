<?php

namespace App\Features\Communications\Api;

use AIArmada\Communications\Enums\NotificationFamily as PackageNotificationFamily;
final readonly class NotificationCatalog
{
    /**
     * @return array<string, array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     default_cadence: NotificationCadence,
     *     triggers: list<string>
     * }>
     */
    public static function families(): array
    {
        return [
            NotificationFamily::FollowedContent->value => [
                'key' => NotificationFamily::FollowedContent->value,
                'label' => __('notifications.families.followed_content.label'),
                'description' => __('notifications.families.followed_content.description'),
                'default_cadence' => NotificationCadence::Daily,
                'triggers' => [
                    NotificationTrigger::FollowedPersonEvent->value,
                    NotificationTrigger::FollowedInstitutionEvent->value,
                    NotificationTrigger::FollowedSeriesEvent->value,
                    NotificationTrigger::FollowedReferenceEvent->value,
                ],
            ],
            NotificationFamily::SavedSearchMatches->value => [
                'key' => NotificationFamily::SavedSearchMatches->value,
                'label' => __('notifications.families.saved_search_matches.label'),
                'description' => __('notifications.families.saved_search_matches.description'),
                'default_cadence' => NotificationCadence::Daily,
                'triggers' => [
                    NotificationTrigger::SavedSearchMatch->value,
                ],
            ],
            NotificationFamily::EventUpdates->value => [
                'key' => NotificationFamily::EventUpdates->value,
                'label' => __('notifications.families.event_updates.label'),
                'description' => __('notifications.families.event_updates.description'),
                'default_cadence' => NotificationCadence::Instant,
                'triggers' => [
                    NotificationTrigger::EventApproved->value,
                    NotificationTrigger::EventCancelled->value,
                    NotificationTrigger::EventScheduleChanged->value,
                    NotificationTrigger::EventVenueChanged->value,
                    NotificationTrigger::EventDetailsChanged->value,
                    NotificationTrigger::EventReplacementLinked->value,
                ],
            ],
            NotificationFamily::EventReminders->value => [
                'key' => NotificationFamily::EventReminders->value,
                'label' => __('notifications.families.event_reminders.label'),
                'description' => __('notifications.families.event_reminders.description'),
                'default_cadence' => NotificationCadence::Instant,
                'triggers' => [
                    NotificationTrigger::Reminder24Hours->value,
                    NotificationTrigger::Reminder2Hours->value,
                    NotificationTrigger::CheckinOpen->value,
                ],
            ],
            NotificationFamily::RegistrationCheckin->value => [
                'key' => NotificationFamily::RegistrationCheckin->value,
                'label' => __('notifications.families.registration_checkin.label'),
                'description' => __('notifications.families.registration_checkin.description'),
                'default_cadence' => NotificationCadence::Instant,
                'triggers' => [
                    NotificationTrigger::RegistrationConfirmed->value,
                    NotificationTrigger::RegistrationEventChanged->value,
                    NotificationTrigger::CheckinConfirmed->value,
                ],
            ],
            NotificationFamily::SubmissionWorkflow->value => [
                'key' => NotificationFamily::SubmissionWorkflow->value,
                'label' => __('notifications.families.submission_workflow.label'),
                'description' => __('notifications.families.submission_workflow.description'),
                'default_cadence' => NotificationCadence::Instant,
                'triggers' => [
                    NotificationTrigger::SubmissionReceived->value,
                    NotificationTrigger::SubmissionApproved->value,
                    NotificationTrigger::SubmissionRejected->value,
                    NotificationTrigger::SubmissionNeedsChanges->value,
                    NotificationTrigger::SubmissionCancelled->value,
                    NotificationTrigger::SubmissionRemoderated->value,
                ],
            ],
            NotificationFamily::Sharing->value => [
                'key' => NotificationFamily::Sharing->value,
                'label' => __('notifications.families.sharing.label'),
                'description' => __('notifications.families.sharing.description'),
                'default_cadence' => NotificationCadence::Weekly,
                'triggers' => [
                    NotificationTrigger::ShareGratitudeDigest->value,
                ],
            ],
        ];
    }

    /**
     * @return array<string, array{
     *     key: string,
     *     family: NotificationFamily,
     *     label: string,
     *     description: string
     * }>
     */
    public static function triggers(): array
    {
        return [
            NotificationTrigger::FollowedPersonEvent->value => self::trigger(
                NotificationTrigger::FollowedPersonEvent,
                NotificationFamily::FollowedContent,
                'followed_person_event',
            ),
            NotificationTrigger::FollowedInstitutionEvent->value => self::trigger(
                NotificationTrigger::FollowedInstitutionEvent,
                NotificationFamily::FollowedContent,
                'followed_institution_event',
            ),
            NotificationTrigger::FollowedSeriesEvent->value => self::trigger(
                NotificationTrigger::FollowedSeriesEvent,
                NotificationFamily::FollowedContent,
                'followed_series_event',
            ),
            NotificationTrigger::FollowedReferenceEvent->value => self::trigger(
                NotificationTrigger::FollowedReferenceEvent,
                NotificationFamily::FollowedContent,
                'followed_reference_event',
            ),
            NotificationTrigger::SavedSearchMatch->value => self::trigger(
                NotificationTrigger::SavedSearchMatch,
                NotificationFamily::SavedSearchMatches,
                'saved_search_match',
            ),
            NotificationTrigger::EventApproved->value => self::trigger(
                NotificationTrigger::EventApproved,
                NotificationFamily::EventUpdates,
                'event_approved',
            ),
            NotificationTrigger::EventCancelled->value => self::trigger(
                NotificationTrigger::EventCancelled,
                NotificationFamily::EventUpdates,
                'event_cancelled',
            ),
            NotificationTrigger::EventScheduleChanged->value => self::trigger(
                NotificationTrigger::EventScheduleChanged,
                NotificationFamily::EventUpdates,
                'event_schedule_changed',
            ),
            NotificationTrigger::EventVenueChanged->value => self::trigger(
                NotificationTrigger::EventVenueChanged,
                NotificationFamily::EventUpdates,
                'event_venue_changed',
            ),
            NotificationTrigger::EventDetailsChanged->value => self::trigger(
                NotificationTrigger::EventDetailsChanged,
                NotificationFamily::EventUpdates,
                'event_details_changed',
            ),
            NotificationTrigger::EventReplacementLinked->value => self::trigger(
                NotificationTrigger::EventReplacementLinked,
                NotificationFamily::EventUpdates,
                'event_replacement_linked',
            ),
            NotificationTrigger::Reminder24Hours->value => self::trigger(
                NotificationTrigger::Reminder24Hours,
                NotificationFamily::EventReminders,
                'reminder_24_hours',
            ),
            NotificationTrigger::Reminder2Hours->value => self::trigger(
                NotificationTrigger::Reminder2Hours,
                NotificationFamily::EventReminders,
                'reminder_2_hours',
            ),
            NotificationTrigger::CheckinOpen->value => self::trigger(
                NotificationTrigger::CheckinOpen,
                NotificationFamily::EventReminders,
                'checkin_open',
            ),
            NotificationTrigger::RegistrationConfirmed->value => self::trigger(
                NotificationTrigger::RegistrationConfirmed,
                NotificationFamily::RegistrationCheckin,
                'registration_confirmed',
            ),
            NotificationTrigger::RegistrationEventChanged->value => self::trigger(
                NotificationTrigger::RegistrationEventChanged,
                NotificationFamily::RegistrationCheckin,
                'registration_event_changed',
            ),
            NotificationTrigger::CheckinConfirmed->value => self::trigger(
                NotificationTrigger::CheckinConfirmed,
                NotificationFamily::RegistrationCheckin,
                'checkin_confirmed',
            ),
            NotificationTrigger::SubmissionReceived->value => self::trigger(
                NotificationTrigger::SubmissionReceived,
                NotificationFamily::SubmissionWorkflow,
                'submission_received',
            ),
            NotificationTrigger::SubmissionApproved->value => self::trigger(
                NotificationTrigger::SubmissionApproved,
                NotificationFamily::SubmissionWorkflow,
                'submission_approved',
            ),
            NotificationTrigger::SubmissionRejected->value => self::trigger(
                NotificationTrigger::SubmissionRejected,
                NotificationFamily::SubmissionWorkflow,
                'submission_rejected',
            ),
            NotificationTrigger::SubmissionNeedsChanges->value => self::trigger(
                NotificationTrigger::SubmissionNeedsChanges,
                NotificationFamily::SubmissionWorkflow,
                'submission_needs_changes',
            ),
            NotificationTrigger::SubmissionCancelled->value => self::trigger(
                NotificationTrigger::SubmissionCancelled,
                NotificationFamily::SubmissionWorkflow,
                'submission_cancelled',
            ),
            NotificationTrigger::SubmissionRemoderated->value => self::trigger(
                NotificationTrigger::SubmissionRemoderated,
                NotificationFamily::SubmissionWorkflow,
                'submission_remoderated',
            ),
            NotificationTrigger::ShareGratitudeDigest->value => self::trigger(
                NotificationTrigger::ShareGratitudeDigest,
                NotificationFamily::Sharing,
                'share_gratitude_digest',
            ),
        ];
    }

    /**
     * @return list<string>
     */
    public static function supportedChannels(): array
    {
        return array_map(
            static fn (NotificationChannel $channel): string => $channel->value,
            NotificationChannel::supported(),
        );
    }

    /**
     * Value stored on inbox rows for an app-level notification family key.
     */
    public static function inboxFamilyValue(string $family): ?string
    {
        $appFamily = NotificationFamily::tryFrom($family);

        return $appFamily !== null ? EnumMapper::toPkgFamily($appFamily)->value : null;
    }

    /**
     * Icon used to represent an inbox notification family.
     */
    public static function familyIcon(PackageNotificationFamily $family): string
    {
        return match ($family) {
            PackageNotificationFamily::FollowUp,
            PackageNotificationFamily::MembershipUpdate => 'heroicon-o-user-group',
            PackageNotificationFamily::Recommendation => 'heroicon-o-magnifying-glass',
            PackageNotificationFamily::EventUpdate,
            PackageNotificationFamily::EventRescheduled,
            PackageNotificationFamily::EventPromotion => 'heroicon-o-calendar-days',
            PackageNotificationFamily::EventReminder => 'heroicon-o-clock',
            PackageNotificationFamily::EventCancellation => 'heroicon-o-x-circle',
            PackageNotificationFamily::RegistrationConfirmation,
            PackageNotificationFamily::RegistrationReminder,
            PackageNotificationFamily::RegistrationChanged,
            PackageNotificationFamily::RegistrationCancelled,
            PackageNotificationFamily::CheckInAvailable,
            PackageNotificationFamily::TicketIssued => 'heroicon-o-ticket',
            PackageNotificationFamily::PaymentReceived,
            PackageNotificationFamily::PaymentFailed,
            PackageNotificationFamily::RefundProcessed => 'heroicon-o-banknotes',
            PackageNotificationFamily::SpeakerInvitation,
            PackageNotificationFamily::SpeakerConfirmation,
            PackageNotificationFamily::SpeakerChange => 'heroicon-o-microphone',
            PackageNotificationFamily::InstitutionInvitation,
            PackageNotificationFamily::InstitutionClaim => 'heroicon-o-building-office',
            PackageNotificationFamily::SecurityAlert,
            PackageNotificationFamily::PasswordChanged,
            PackageNotificationFamily::NewDeviceLogin,
            PackageNotificationFamily::TwoFactorDisabled => 'heroicon-o-shield-check',
            PackageNotificationFamily::AchievementUnlocked,
            PackageNotificationFamily::MilestoneReached => 'heroicon-o-trophy',
            PackageNotificationFamily::SystemAnnouncement,
            PackageNotificationFamily::MaintenanceNotice,
            PackageNotificationFamily::PolicyUpdate => 'heroicon-o-megaphone',
            PackageNotificationFamily::DigestDaily,
            PackageNotificationFamily::DigestWeekly => 'heroicon-o-inbox',
            default => 'heroicon-o-bell',
        };
    }

    /**
     * @return array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     default_cadence: NotificationCadence,
     *     triggers: list<string>
     * }
     */
    public static function familyDefinition(NotificationFamily|string $family): array
    {
        $key = $family instanceof NotificationFamily ? $family->value : $family;

        return self::families()[$key];
    }

    /**
     * @return array{
     *     key: string,
     *     family: NotificationFamily,
     *     label: string,
     *     description: string
     * }
     */
    public static function triggerDefinition(NotificationTrigger|string $trigger): array
    {
        $key = $trigger instanceof NotificationTrigger ? $trigger->value : $trigger;

        return self::triggers()[$key];
    }

    /**
     * @return array{
     *     key: string,
     *     family: NotificationFamily,
     *     label: string,
     *     description: string
     * }
     */
    private static function trigger(
        NotificationTrigger $trigger,
        NotificationFamily $family,
        string $translationKey,
    ): array {
        return [
            'key' => $trigger->value,
            'family' => $family,
            'label' => __("notifications.triggers.{$translationKey}.label"),
            'description' => __("notifications.triggers.{$translationKey}.description"),
        ];
    }
}
