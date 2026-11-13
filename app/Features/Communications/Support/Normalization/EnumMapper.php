<?php

namespace App\Features\Communications\Support\Normalization;

use AIArmada\Communications\Enums\NotificationFamily as PkgFamily;
use AIArmada\Communications\Enums\NotificationPriority as PkgPriority;
use AIArmada\Communications\Enums\NotificationTrigger as PkgTrigger;
use App\Features\Communications\Api\NotificationFamily as AppFamily;
use App\Features\Communications\Api\NotificationPriority as AppPriority;
use App\Features\Communications\Api\NotificationTrigger as AppTrigger;

final readonly class EnumMapper
{
    public static function toPkgFamily(AppFamily $family): PkgFamily
    {
        return match ($family) {
            AppFamily::FollowedContent => PkgFamily::FollowUp,
            AppFamily::SavedSearchMatches => PkgFamily::Recommendation,
            AppFamily::EventUpdates => PkgFamily::EventUpdate,
            AppFamily::EventReminders => PkgFamily::EventReminder,
            AppFamily::RegistrationCheckin => PkgFamily::CheckInAvailable,
            AppFamily::SubmissionWorkflow => PkgFamily::SystemAnnouncement,
            AppFamily::Sharing => PkgFamily::DigestWeekly,
        };
    }

    public static function toPkgTrigger(AppTrigger $trigger): PkgTrigger
    {
        return match ($trigger) {
            AppTrigger::FollowedPersonEvent,
            AppTrigger::FollowedInstitutionEvent,
            AppTrigger::FollowedSeriesEvent,
            AppTrigger::FollowedReferenceEvent => PkgTrigger::FollowActivity,
            AppTrigger::SavedSearchMatch,
            AppTrigger::Reminder24Hours,
            AppTrigger::Reminder2Hours,
            AppTrigger::ShareGratitudeDigest => PkgTrigger::ScheduledDispatch,
            AppTrigger::EventApproved,
            AppTrigger::SubmissionApproved => PkgTrigger::EventPublished,
            AppTrigger::EventCancelled,
            AppTrigger::SubmissionRejected,
            AppTrigger::SubmissionCancelled => PkgTrigger::EventCancelled,
            AppTrigger::EventScheduleChanged,
            AppTrigger::EventVenueChanged,
            AppTrigger::EventDetailsChanged,
            AppTrigger::EventReplacementLinked,
            AppTrigger::SubmissionNeedsChanges,
            AppTrigger::SubmissionRemoderated => PkgTrigger::EventUpdated,
            AppTrigger::CheckinOpen,
            AppTrigger::CheckinConfirmed => PkgTrigger::CheckInRecorded,
            AppTrigger::RegistrationConfirmed => PkgTrigger::RegistrationConfirmed,
            AppTrigger::RegistrationEventChanged => PkgTrigger::RegistrationChanged,
            AppTrigger::SubmissionReceived => PkgTrigger::SystemAlert,
        };
    }

    public static function toPkgPriority(AppPriority $priority): PkgPriority
    {
        return match ($priority) {
            AppPriority::Low => PkgPriority::Low,
            AppPriority::Medium => PkgPriority::Normal,
            AppPriority::High => PkgPriority::High,
            AppPriority::Urgent => PkgPriority::Urgent,
        };
    }

    public static function toAppFamily(PkgFamily $family): ?AppFamily
    {
        return match ($family) {
            PkgFamily::FollowUp => AppFamily::FollowedContent,
            PkgFamily::Recommendation => AppFamily::SavedSearchMatches,
            PkgFamily::EventUpdate => AppFamily::EventUpdates,
            PkgFamily::EventReminder => AppFamily::EventReminders,
            PkgFamily::CheckInAvailable => AppFamily::RegistrationCheckin,
            PkgFamily::SystemAnnouncement => AppFamily::SubmissionWorkflow,
            PkgFamily::DigestWeekly => AppFamily::Sharing,
            default => null,
        };
    }

    public static function toAppPriority(PkgPriority $priority): AppPriority
    {
        return match ($priority) {
            PkgPriority::Low => AppPriority::Low,
            PkgPriority::Normal => AppPriority::Medium,
            PkgPriority::High => AppPriority::High,
            PkgPriority::Urgent => AppPriority::Urgent,
        };
    }
}
