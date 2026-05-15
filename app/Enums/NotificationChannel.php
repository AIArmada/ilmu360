<?php

namespace App\Enums;

use App\Notifications\Channels\PushChannel;
use App\Notifications\Channels\WhatsappChannel;

enum NotificationChannel: string
{
    case Email = 'email';
    case Sms = 'sms';
    case Whatsapp = 'whatsapp';
    case Telegram = 'telegram';
    case Line = 'line';
    case Wechat = 'wechat';
    case Messenger = 'messenger';
    case InstagramDm = 'instagram_dm';
    case Push = 'push';
    case InApp = 'in_app';

    public function label(): string
    {
        return match ($this) {
            self::Email => __('Email'),
            self::Whatsapp => __('WhatsApp'),
            self::Push => __('Push Notification'),
            self::InApp => __('In-app'),
            self::Sms => __('SMS'),
            self::Telegram => __('Telegram'),
            self::Line => __('LINE'),
            self::Wechat => __('WeChat'),
            self::Messenger => __('Messenger'),
            self::InstagramDm => __('Instagram DM'),
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

    public function laravelChannel(): string
    {
        return match ($this) {
            self::Email => 'mail',
            self::InApp => 'database',
            self::Push => PushChannel::class,
            self::Whatsapp => WhatsappChannel::class,
            default => throw new \LogicException("Channel [{$this->value}] is not supported by the Laravel notification runtime."),
        };
    }
}
