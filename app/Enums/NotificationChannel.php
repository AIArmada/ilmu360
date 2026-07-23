<?php

namespace App\Enums;

enum NotificationChannel: string
{
    case Email = 'email';
    case Whatsapp = 'whatsapp';
    case Push = 'push';
    case InApp = 'in_app';

    public function label(): string
    {
        return match ($this) {
            self::Email => __('Email'),
            self::Whatsapp => __('WhatsApp'),
            self::Push => __('Push Notification'),
            self::InApp => __('In-app'),
        };
    }

    /**
     * @return list<self>
     */
    public static function userSelectable(): array
    {
        return [
            self::Email,
            self::InApp,
            self::Push,
            self::Whatsapp,
        ];
    }
}
