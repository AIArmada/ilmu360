<?php

namespace App\Enums;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum SocialMediaPlatform: string implements HasIcon, HasLabel
{
    case Facebook = 'facebook';
    case Twitter = 'twitter';
    case Instagram = 'instagram';
    case YouTube = 'youtube';
    case TikTok = 'tiktok';
    case Telegram = 'telegram';
    case WhatsApp = 'whatsapp';
    case LinkedIn = 'linkedin';
    case Wikipedia = 'wikipedia';
    case Website = 'website';
    case Threads = 'threads';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Facebook => __('Facebook'),
            self::Twitter => __('Twitter / X'),
            self::Instagram => __('Instagram'),
            self::YouTube => __('YouTube'),
            self::TikTok => __('TikTok'),
            self::Telegram => __('Telegram'),
            self::WhatsApp => __('WhatsApp'),
            self::LinkedIn => __('LinkedIn'),
            self::Wikipedia => __('Wikipedia'),
            self::Website => __('Laman Web'),
            self::Threads => __('Threads'),
            self::Other => __('Lain-lain'),
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Facebook, self::Website => 'heroicon-m-globe-alt',
            self::Twitter => 'heroicon-m-chat-bubble-left',
            self::Instagram => 'heroicon-m-camera',
            self::YouTube => 'heroicon-m-play',
            self::TikTok => 'heroicon-m-musical-note',
            self::Telegram => 'heroicon-m-paper-airplane',
            self::WhatsApp => 'heroicon-m-phone',
            self::LinkedIn => 'heroicon-m-briefcase',
            self::Wikipedia => 'heroicon-m-book-open',
            default => 'heroicon-m-link',
        };
    }

    /**
     * Get URL validation pattern for this platform.
     */
    public function getUrlPattern(): ?string
    {
        return match ($this) {
            self::Facebook => 'https://*facebook.com/*',
            self::Twitter => 'https://x.com/*',
            self::Instagram => 'https://*instagram.com/*',
            self::YouTube => 'https://*youtube.com/*',
            self::TikTok => 'https://*tiktok.com/*',
            self::Telegram => 'https://t.me/*',
            self::WhatsApp => 'https://wa.me/*',
            self::LinkedIn => 'https://*linkedin.com/*',
            self::Wikipedia => 'https://*wikipedia.org/*',
            self::Threads => 'https://*threads.net/*',
            default => null,
        };
    }
}
