<?php

declare(strict_types=1);

namespace App\Enums;

enum EventAttendanceStatus: string
{
    case Attended = 'attended';
    case DidNotAttend = 'did_not_attend';
    case NotRecorded = 'not_recorded';

    public function getLabel(): string
    {
        return match ($this) {
            self::Attended => __('Attended'),
            self::DidNotAttend => __('Did not attend'),
            self::NotRecorded => __('Not recorded yet'),
        };
    }
}
