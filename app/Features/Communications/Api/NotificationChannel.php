<?php

namespace App\Features\Communications\Api;

enum NotificationChannel: string
{
    case Email = 'email';
    case Push = 'push';
    case InApp = 'in_app';

    public function label(): string
    {
        return match ($this) {
            self::Email => __('Email'),
            self::Push => __('Push Notification'),
            self::InApp => __('In-app'),
        };
    }

    /**
     * Channels a user may opt into. In-app delivery is implicit for every
     * enabled group, so it is not a user-selectable switch.
     *
     * @return list<self>
     */
    public static function userSelectable(): array
    {
        return [
            self::Email,
            self::Push,
        ];
    }

    /**
     * @return list<self>
     */
    public static function supported(): array
    {
        return [
            self::InApp,
            self::Email,
            self::Push,
        ];
    }
}
