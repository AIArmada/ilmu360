<?php

namespace App\Enums;

enum EventChangeType: string
{
    case Cancelled = 'cancelled';
    case Postponed = 'postponed';
    case RescheduledEarlier = 'rescheduled_earlier';
    case RescheduledLater = 'rescheduled_later';
    case ScheduleChanged = 'schedule_changed';
    case LocationChanged = 'location_changed';
    case SpeakerChanged = 'speaker_changed';
    case TopicChanged = 'topic_changed';
    case ReferenceChanged = 'reference_changed';
    case OrganizerChanged = 'organizer_changed';
    case ReplacementLinked = 'replacement_linked';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cancelled => __('Cancelled'),
            self::Postponed => __('Postponed'),
            self::RescheduledEarlier => __('Rescheduled earlier'),
            self::RescheduledLater => __('Rescheduled later'),
            self::ScheduleChanged => __('Schedule changed'),
            self::LocationChanged => __('Location changed'),
            self::SpeakerChanged => __('Speaker changed'),
            self::TopicChanged => __('Topic changed'),
            self::ReferenceChanged => __('Reference changed'),
            self::OrganizerChanged => __('Organizer changed'),
            self::ReplacementLinked => __('Replacement linked'),
            self::Other => __('Other update'),
        };
    }

    public function publicBadgeLabel(): string
    {
        return match ($this) {
            self::Cancelled => __('Dibatalkan'),
            self::Postponed => __('Ditangguhkan'),
            self::RescheduledEarlier,
            self::RescheduledLater,
            self::ScheduleChanged => __('Masa Berubah'),
            self::LocationChanged => __('Lokasi Berubah'),
            default => __('Maklumat Dikemas Kini'),
        };
    }
}
