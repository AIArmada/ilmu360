<?php

namespace App\Features\Communications\Support\Normalization;

use AIArmada\Communications\Enums\NotificationFamily as PackageNotificationFamily;
use AIArmada\Communications\Enums\NotificationPriority as PackageNotificationPriority;
use App\Features\Communications\Api\NotificationFamily;
use App\Features\Communications\Api\NotificationPriority;
use Illuminate\Support\Str;

final readonly class NotificationInboxPresentation
{
    public static function storedFamilyValue(string $familyValue): ?string
    {
        $family = NotificationFamily::tryFrom($familyValue);

        return $family !== null ? EnumMapper::toPkgFamily($family)->value : null;
    }

    public static function appFamily(string $familyValue): ?NotificationFamily
    {
        $family = PackageNotificationFamily::tryFrom($familyValue);

        return $family !== null ? EnumMapper::toAppFamily($family) : null;
    }

    public static function familyLabel(string $familyValue): string
    {
        $family = PackageNotificationFamily::tryFrom($familyValue);
        $appFamily = self::appFamily($familyValue);

        if ($appFamily !== null) {
            return \App\Features\Communications\Api\NotificationCatalog::familyDefinition($appFamily)['label'];
        }

        return $family?->label() ?? Str::headline($familyValue);
    }

    public static function familyIcon(string $familyValue): string
    {
        return match (PackageNotificationFamily::tryFrom($familyValue)) {
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

    public static function priorityLabel(string $priorityValue): string
    {
        $packagePriority = PackageNotificationPriority::tryFrom($priorityValue);

        return $packagePriority !== null
            ? __('notifications.options.priority.'.EnumMapper::toAppPriority($packagePriority)->value)
            : Str::headline($priorityValue);
    }

    public static function priorityTone(string $priorityValue): string
    {
        $packagePriority = PackageNotificationPriority::tryFrom($priorityValue);

        return $packagePriority === null ? 'zinc' : match (EnumMapper::toAppPriority($packagePriority)) {
            NotificationPriority::Urgent => 'red',
            NotificationPriority::High => 'amber',
            NotificationPriority::Medium => 'sky',
            default => 'zinc',
        };
    }
}
