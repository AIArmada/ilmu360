<?php

namespace App\Support\Communications;

use AIArmada\Communications\Enums\NotificationFamily as PkgFamily;
use AIArmada\Communications\Enums\NotificationPriority as PkgPriority;
use AIArmada\Communications\Enums\NotificationTrigger as PkgTrigger;
use App\Enums\NotificationFamily as AppFamily;
use App\Enums\NotificationPriority as AppPriority;
use App\Enums\NotificationTrigger as AppTrigger;

final class EnumMapper
{
    private const array FAMILY_MAP = [
        'followed_content' => 'follow_up',
        'saved_search_matches' => 'recommendation',
        'event_updates' => 'event_update',
        'event_reminders' => 'event_reminder',
        'registration_checkin' => 'check_in_available',
        'submission_workflow' => 'system_announcement',
    ];

    private const array TRIGGER_MAP = [
        'followed_speaker_event' => 'follow_activity',
        'followed_institution_event' => 'follow_activity',
        'followed_series_event' => 'follow_activity',
        'followed_reference_event' => 'follow_activity',
        'saved_search_match' => 'scheduled_dispatch',
        'event_approved' => 'event_published',
        'event_cancelled' => 'event_cancelled',
        'event_schedule_changed' => 'event_updated',
        'event_venue_changed' => 'event_updated',
        'event_details_changed' => 'event_updated',
        'event_replacement_linked' => 'event_updated',
        'reminder_24_hours' => 'scheduled_dispatch',
        'reminder_2_hours' => 'scheduled_dispatch',
        'checkin_open' => 'check_in_recorded',
        'registration_confirmed' => 'registration_confirmed',
        'registration_event_changed' => 'registration_changed',
        'checkin_confirmed' => 'check_in_recorded',
        'submission_received' => 'system_alert',
        'submission_approved' => 'event_published',
        'submission_rejected' => 'event_cancelled',
        'submission_needs_changes' => 'event_updated',
        'submission_cancelled' => 'event_cancelled',
        'submission_remoderated' => 'event_updated',
    ];

    private const array PRIORITY_MAP = [
        'low' => 'low',
        'medium' => 'normal',
        'high' => 'high',
        'urgent' => 'urgent',
    ];

    private const array REVERSE_PRIORITY_MAP = [
        'low' => 'low',
        'normal' => 'medium',
        'high' => 'high',
        'urgent' => 'urgent',
    ];

    private const array REVERSE_FAMILY_MAP = [
        'follow_up' => 'followed_content',
        'recommendation' => 'saved_search_matches',
        'event_update' => 'event_updates',
        'event_reminder' => 'event_reminders',
        'check_in_available' => 'registration_checkin',
        'system_announcement' => 'submission_workflow',
    ];

    private const array REVERSE_TRIGGER_MAP = [
        'follow_activity' => 'followed_speaker_event',
        'scheduled_dispatch' => 'saved_search_match',
        'event_published' => 'event_approved',
        'event_cancelled' => 'event_cancelled',
        'event_updated' => 'event_schedule_changed',
        'check_in_recorded' => 'checkin_open',
        'registration_confirmed' => 'registration_confirmed',
        'registration_changed' => 'registration_event_changed',
        'system_alert' => 'submission_received',
    ];

    public static function toPkgFamily(AppFamily $family): PkgFamily
    {
        return PkgFamily::tryFrom(self::FAMILY_MAP[$family->value] ?? 'system_announcement')
            ?? PkgFamily::SystemAnnouncement;
    }

    public static function toPkgTrigger(AppTrigger $trigger): PkgTrigger
    {
        return PkgTrigger::tryFrom(self::TRIGGER_MAP[$trigger->value] ?? 'scheduled_dispatch')
            ?? PkgTrigger::ScheduledDispatch;
    }

    public static function toPkgPriority(AppPriority $priority): PkgPriority
    {
        return PkgPriority::tryFrom(self::PRIORITY_MAP[$priority->value] ?? 'normal')
            ?? PkgPriority::Normal;
    }

    public static function toAppFamily(PkgFamily $family): ?AppFamily
    {
        $appValue = self::REVERSE_FAMILY_MAP[$family->value] ?? null;

        return $appValue !== null ? AppFamily::tryFrom($appValue) : null;
    }

    public static function toAppTrigger(PkgTrigger $trigger): ?AppTrigger
    {
        $appValue = self::REVERSE_TRIGGER_MAP[$trigger->value] ?? null;

        return $appValue !== null ? AppTrigger::tryFrom($appValue) : null;
    }

    public static function toAppPriority(PkgPriority $priority): AppPriority
    {
        return AppPriority::tryFrom(self::REVERSE_PRIORITY_MAP[$priority->value] ?? 'medium');
    }
}
