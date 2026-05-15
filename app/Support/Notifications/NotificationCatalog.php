<?php

namespace App\Support\Notifications;

use App\Enums\NotificationCadence;
use App\Enums\NotificationChannel;
use App\Enums\NotificationFamily;
use App\Enums\NotificationPriority;
use App\Enums\NotificationTrigger;

class NotificationCatalog
{
    /**
     * @return array<string, array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     default_cadence: NotificationCadence,
     *     allowed_channels: list<string>,
     *     default_channels: list<string>,
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
                'allowed_channels' => [
                    NotificationChannel::Email->value,
                    NotificationChannel::InApp->value,
                    NotificationChannel::Push->value,
                ],
                'default_channels' => [
                    NotificationChannel::Email->value,
                    NotificationChannel::InApp->value,
                ],
                'triggers' => [
                    NotificationTrigger::FollowedSpeakerEvent->value,
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
                'allowed_channels' => [
                    NotificationChannel::Email->value,
                    NotificationChannel::InApp->value,
                    NotificationChannel::Push->value,
                ],
                'default_channels' => [
                    NotificationChannel::Email->value,
                    NotificationChannel::InApp->value,
                ],
                'triggers' => [
                    NotificationTrigger::SavedSearchMatch->value,
                ],
            ],
            NotificationFamily::EventUpdates->value => [
                'key' => NotificationFamily::EventUpdates->value,
                'label' => __('notifications.families.event_updates.label'),
                'description' => __('notifications.families.event_updates.description'),
                'default_cadence' => NotificationCadence::Instant,
                'allowed_channels' => [
                    NotificationChannel::Email->value,
                    NotificationChannel::InApp->value,
                    NotificationChannel::Push->value,
                    NotificationChannel::Whatsapp->value,
                ],
                'default_channels' => [
                    NotificationChannel::InApp->value,
                    NotificationChannel::Email->value,
                ],
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
                'allowed_channels' => [
                    NotificationChannel::InApp->value,
                    NotificationChannel::Push->value,
                    NotificationChannel::Email->value,
                    NotificationChannel::Whatsapp->value,
                ],
                'default_channels' => [
                    NotificationChannel::Push->value,
                    NotificationChannel::InApp->value,
                ],
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
                'allowed_channels' => [
                    NotificationChannel::Email->value,
                    NotificationChannel::InApp->value,
                    NotificationChannel::Push->value,
                    NotificationChannel::Whatsapp->value,
                ],
                'default_channels' => [
                    NotificationChannel::InApp->value,
                    NotificationChannel::Email->value,
                ],
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
                'allowed_channels' => [
                    NotificationChannel::Email->value,
                    NotificationChannel::InApp->value,
                    NotificationChannel::Push->value,
                    NotificationChannel::Whatsapp->value,
                ],
                'default_channels' => [
                    NotificationChannel::Email->value,
                    NotificationChannel::InApp->value,
                ],
                'triggers' => [
                    NotificationTrigger::SubmissionReceived->value,
                    NotificationTrigger::SubmissionApproved->value,
                    NotificationTrigger::SubmissionRejected->value,
                    NotificationTrigger::SubmissionNeedsChanges->value,
                    NotificationTrigger::SubmissionCancelled->value,
                    NotificationTrigger::SubmissionRemoderated->value,
                ],
            ],
        ];
    }

    /**
     * @return array<string, array{
     *     key: string,
     *     family: NotificationFamily,
     *     label: string,
     *     description: string,
     *     default_cadence: NotificationCadence,
     *     allowed_channels: list<string>,
     *     default_channels: list<string>,
     *     priority: NotificationPriority
     * }>
     */
    public static function triggers(): array
    {
        return [
            NotificationTrigger::FollowedSpeakerEvent->value => self::buildTriggerDefinition(
                NotificationTrigger::FollowedSpeakerEvent,
                NotificationFamily::FollowedContent,
                'followed_speaker_event',
                NotificationCadence::Daily,
                [NotificationChannel::Email->value, NotificationChannel::InApp->value, NotificationChannel::Push->value],
                [NotificationChannel::Email->value, NotificationChannel::InApp->value],
                NotificationPriority::Low,
            ),
            NotificationTrigger::FollowedInstitutionEvent->value => self::buildTriggerDefinition(
                NotificationTrigger::FollowedInstitutionEvent,
                NotificationFamily::FollowedContent,
                'followed_institution_event',
                NotificationCadence::Daily,
                [NotificationChannel::Email->value, NotificationChannel::InApp->value, NotificationChannel::Push->value],
                [NotificationChannel::Email->value, NotificationChannel::InApp->value],
                NotificationPriority::Low,
            ),
            NotificationTrigger::FollowedSeriesEvent->value => self::buildTriggerDefinition(
                NotificationTrigger::FollowedSeriesEvent,
                NotificationFamily::FollowedContent,
                'followed_series_event',
                NotificationCadence::Daily,
                [NotificationChannel::Email->value, NotificationChannel::InApp->value, NotificationChannel::Push->value],
                [NotificationChannel::Email->value, NotificationChannel::InApp->value],
                NotificationPriority::Low,
            ),
            NotificationTrigger::FollowedReferenceEvent->value => self::buildTriggerDefinition(
                NotificationTrigger::FollowedReferenceEvent,
                NotificationFamily::FollowedContent,
                'followed_reference_event',
                NotificationCadence::Daily,
                [NotificationChannel::Email->value, NotificationChannel::InApp->value, NotificationChannel::Push->value],
                [NotificationChannel::Email->value, NotificationChannel::InApp->value],
                NotificationPriority::Low,
            ),
            NotificationTrigger::SavedSearchMatch->value => self::buildTriggerDefinition(
                NotificationTrigger::SavedSearchMatch,
                NotificationFamily::SavedSearchMatches,
                'saved_search_match',
                NotificationCadence::Daily,
                [NotificationChannel::Email->value, NotificationChannel::InApp->value, NotificationChannel::Push->value],
                [NotificationChannel::Email->value, NotificationChannel::InApp->value],
                NotificationPriority::Low,
            ),
            NotificationTrigger::EventApproved->value => self::buildTriggerDefinition(
                NotificationTrigger::EventApproved,
                NotificationFamily::EventUpdates,
                'event_approved',
                NotificationCadence::Instant,
                [NotificationChannel::Email->value, NotificationChannel::InApp->value, NotificationChannel::Push->value],
                [NotificationChannel::InApp->value, NotificationChannel::Email->value],
                NotificationPriority::Medium,
            ),
            NotificationTrigger::EventCancelled->value => self::buildTriggerDefinition(
                NotificationTrigger::EventCancelled,
                NotificationFamily::EventUpdates,
                'event_cancelled',
                NotificationCadence::Instant,
                [NotificationChannel::Email->value, NotificationChannel::InApp->value, NotificationChannel::Push->value, NotificationChannel::Whatsapp->value],
                [NotificationChannel::InApp->value, NotificationChannel::Email->value],
                NotificationPriority::Urgent,
            ),
            NotificationTrigger::EventScheduleChanged->value => self::buildTriggerDefinition(
                NotificationTrigger::EventScheduleChanged,
                NotificationFamily::EventUpdates,
                'event_schedule_changed',
                NotificationCadence::Instant,
                [NotificationChannel::Email->value, NotificationChannel::InApp->value, NotificationChannel::Push->value, NotificationChannel::Whatsapp->value],
                [NotificationChannel::InApp->value, NotificationChannel::Email->value],
                NotificationPriority::High,
            ),
            NotificationTrigger::EventVenueChanged->value => self::buildTriggerDefinition(
                NotificationTrigger::EventVenueChanged,
                NotificationFamily::EventUpdates,
                'event_venue_changed',
                NotificationCadence::Instant,
                [NotificationChannel::Email->value, NotificationChannel::InApp->value, NotificationChannel::Push->value, NotificationChannel::Whatsapp->value],
                [NotificationChannel::InApp->value, NotificationChannel::Email->value],
                NotificationPriority::High,
            ),
            NotificationTrigger::EventDetailsChanged->value => self::buildTriggerDefinition(
                NotificationTrigger::EventDetailsChanged,
                NotificationFamily::EventUpdates,
                'event_details_changed',
                NotificationCadence::Instant,
                [NotificationChannel::Email->value, NotificationChannel::InApp->value, NotificationChannel::Push->value, NotificationChannel::Whatsapp->value],
                [NotificationChannel::InApp->value, NotificationChannel::Email->value],
                NotificationPriority::High,
            ),
            NotificationTrigger::EventReplacementLinked->value => self::buildTriggerDefinition(
                NotificationTrigger::EventReplacementLinked,
                NotificationFamily::EventUpdates,
                'event_replacement_linked',
                NotificationCadence::Instant,
                [NotificationChannel::Email->value, NotificationChannel::InApp->value, NotificationChannel::Push->value, NotificationChannel::Whatsapp->value],
                [NotificationChannel::InApp->value, NotificationChannel::Email->value],
                NotificationPriority::High,
            ),
            NotificationTrigger::Reminder24Hours->value => self::buildTriggerDefinition(
                NotificationTrigger::Reminder24Hours,
                NotificationFamily::EventReminders,
                'reminder_24_hours',
                NotificationCadence::Instant,
                [NotificationChannel::InApp->value, NotificationChannel::Push->value, NotificationChannel::Email->value, NotificationChannel::Whatsapp->value],
                [NotificationChannel::Push->value, NotificationChannel::InApp->value],
                NotificationPriority::Medium,
            ),
            NotificationTrigger::Reminder2Hours->value => self::buildTriggerDefinition(
                NotificationTrigger::Reminder2Hours,
                NotificationFamily::EventReminders,
                'reminder_2_hours',
                NotificationCadence::Instant,
                [NotificationChannel::InApp->value, NotificationChannel::Push->value, NotificationChannel::Email->value, NotificationChannel::Whatsapp->value],
                [NotificationChannel::Push->value, NotificationChannel::InApp->value],
                NotificationPriority::Urgent,
            ),
            NotificationTrigger::CheckinOpen->value => self::buildTriggerDefinition(
                NotificationTrigger::CheckinOpen,
                NotificationFamily::EventReminders,
                'checkin_open',
                NotificationCadence::Instant,
                [NotificationChannel::InApp->value, NotificationChannel::Push->value, NotificationChannel::Email->value, NotificationChannel::Whatsapp->value],
                [NotificationChannel::Push->value, NotificationChannel::InApp->value],
                NotificationPriority::Urgent,
            ),
            NotificationTrigger::RegistrationConfirmed->value => self::buildTriggerDefinition(
                NotificationTrigger::RegistrationConfirmed,
                NotificationFamily::RegistrationCheckin,
                'registration_confirmed',
                NotificationCadence::Instant,
                [NotificationChannel::Email->value, NotificationChannel::InApp->value, NotificationChannel::Push->value, NotificationChannel::Whatsapp->value],
                [NotificationChannel::InApp->value, NotificationChannel::Email->value],
                NotificationPriority::Medium,
            ),
            NotificationTrigger::RegistrationEventChanged->value => self::buildTriggerDefinition(
                NotificationTrigger::RegistrationEventChanged,
                NotificationFamily::RegistrationCheckin,
                'registration_event_changed',
                NotificationCadence::Instant,
                [NotificationChannel::Email->value, NotificationChannel::InApp->value, NotificationChannel::Push->value, NotificationChannel::Whatsapp->value],
                [NotificationChannel::InApp->value, NotificationChannel::Email->value],
                NotificationPriority::High,
            ),
            NotificationTrigger::CheckinConfirmed->value => self::buildTriggerDefinition(
                NotificationTrigger::CheckinConfirmed,
                NotificationFamily::RegistrationCheckin,
                'checkin_confirmed',
                NotificationCadence::Instant,
                [NotificationChannel::Email->value, NotificationChannel::InApp->value, NotificationChannel::Push->value],
                [NotificationChannel::InApp->value, NotificationChannel::Email->value],
                NotificationPriority::Medium,
            ),
            NotificationTrigger::SubmissionReceived->value => self::buildTriggerDefinition(
                NotificationTrigger::SubmissionReceived,
                NotificationFamily::SubmissionWorkflow,
                'submission_received',
                NotificationCadence::Instant,
                [NotificationChannel::Email->value, NotificationChannel::InApp->value, NotificationChannel::Push->value],
                [NotificationChannel::Email->value, NotificationChannel::InApp->value],
                NotificationPriority::Medium,
            ),
            NotificationTrigger::SubmissionApproved->value => self::buildTriggerDefinition(
                NotificationTrigger::SubmissionApproved,
                NotificationFamily::SubmissionWorkflow,
                'submission_approved',
                NotificationCadence::Instant,
                [NotificationChannel::Email->value, NotificationChannel::InApp->value, NotificationChannel::Push->value, NotificationChannel::Whatsapp->value],
                [NotificationChannel::Email->value, NotificationChannel::InApp->value],
                NotificationPriority::High,
            ),
            NotificationTrigger::SubmissionRejected->value => self::buildTriggerDefinition(
                NotificationTrigger::SubmissionRejected,
                NotificationFamily::SubmissionWorkflow,
                'submission_rejected',
                NotificationCadence::Instant,
                [NotificationChannel::Email->value, NotificationChannel::InApp->value, NotificationChannel::Push->value, NotificationChannel::Whatsapp->value],
                [NotificationChannel::Email->value, NotificationChannel::InApp->value],
                NotificationPriority::High,
            ),
            NotificationTrigger::SubmissionNeedsChanges->value => self::buildTriggerDefinition(
                NotificationTrigger::SubmissionNeedsChanges,
                NotificationFamily::SubmissionWorkflow,
                'submission_needs_changes',
                NotificationCadence::Instant,
                [NotificationChannel::Email->value, NotificationChannel::InApp->value, NotificationChannel::Push->value, NotificationChannel::Whatsapp->value],
                [NotificationChannel::Email->value, NotificationChannel::InApp->value],
                NotificationPriority::High,
            ),
            NotificationTrigger::SubmissionCancelled->value => self::buildTriggerDefinition(
                NotificationTrigger::SubmissionCancelled,
                NotificationFamily::SubmissionWorkflow,
                'submission_cancelled',
                NotificationCadence::Instant,
                [NotificationChannel::Email->value, NotificationChannel::InApp->value, NotificationChannel::Push->value, NotificationChannel::Whatsapp->value],
                [NotificationChannel::Email->value, NotificationChannel::InApp->value],
                NotificationPriority::Urgent,
            ),
            NotificationTrigger::SubmissionRemoderated->value => self::buildTriggerDefinition(
                NotificationTrigger::SubmissionRemoderated,
                NotificationFamily::SubmissionWorkflow,
                'submission_remoderated',
                NotificationCadence::Instant,
                [NotificationChannel::Email->value, NotificationChannel::InApp->value, NotificationChannel::Push->value],
                [NotificationChannel::Email->value, NotificationChannel::InApp->value],
                NotificationPriority::Medium,
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
            NotificationChannel::userSelectable()
        );
    }

    /**
     * @return array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     default_cadence: NotificationCadence,
     *     allowed_channels: list<string>,
     *     default_channels: list<string>,
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
     *     description: string,
     *     default_cadence: NotificationCadence,
     *     allowed_channels: list<string>,
     *     default_channels: list<string>,
     *     priority: NotificationPriority
     * }
     */
    public static function triggerDefinition(NotificationTrigger|string $trigger): array
    {
        $key = $trigger instanceof NotificationTrigger ? $trigger->value : $trigger;

        return self::triggers()[$key];
    }

    /**
     * @param  list<string>  $allowedChannels
     * @param  list<string>  $defaultChannels
     * @return array{
     *     key: string,
     *     family: NotificationFamily,
     *     label: string,
     *     description: string,
     *     default_cadence: NotificationCadence,
     *     allowed_channels: list<string>,
     *     default_channels: list<string>,
     *     priority: NotificationPriority
     * }
     */
    private static function triggerDefinitionFactory(
        NotificationTrigger $trigger,
        NotificationFamily $family,
        string $translationKey,
        NotificationCadence $cadence,
        array $allowedChannels,
        array $defaultChannels,
        NotificationPriority $priority,
    ): array {
        return [
            'key' => $trigger->value,
            'family' => $family,
            'label' => __("notifications.triggers.{$translationKey}.label"),
            'description' => __("notifications.triggers.{$translationKey}.description"),
            'default_cadence' => $cadence,
            'allowed_channels' => $allowedChannels,
            'default_channels' => $defaultChannels,
            'priority' => $priority,
        ];
    }

    /**
     * @param  list<string>  $allowedChannels
     * @param  list<string>  $defaultChannels
     * @return array{
     *     key: string,
     *     family: NotificationFamily,
     *     label: string,
     *     description: string,
     *     default_cadence: NotificationCadence,
     *     allowed_channels: list<string>,
     *     default_channels: list<string>,
     *     priority: NotificationPriority
     * }
     */
    private static function buildTriggerDefinition(
        NotificationTrigger $trigger,
        NotificationFamily $family,
        string $translationKey,
        NotificationCadence $cadence,
        array $allowedChannels,
        array $defaultChannels,
        NotificationPriority $priority,
    ): array {
        return self::triggerDefinitionFactory(
            $trigger,
            $family,
            $translationKey,
            $cadence,
            $allowedChannels,
            $defaultChannels,
            $priority,
        );
    }
}
