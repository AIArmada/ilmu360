<?php

namespace App\Enums;

enum InspirationCategory: string
{
    case QuranQuote = 'quran_quote';
    case HadithQuote = 'hadith_quote';
    case MotivationalQuote = 'motivational_quote';
    case DidYouKnow = 'did_you_know';
    case IslamicFaq = 'islamic_faq';
    case IslamicComic = 'islamic_comic';

    public function label(): string
    {
        return match ($this) {
            self::QuranQuote => __('Petikan Al-Quran'),
            self::HadithQuote => __('Petikan Hadith'),
            self::MotivationalQuote => __('Kata-Kata Motivasi'),
            self::DidYouKnow => __('Tahukah Anda?'),
            self::IslamicFaq => __('FAQ Tentang Islam'),
            self::IslamicComic => __('Komik Islamik'),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::QuranQuote => 'heroicon-o-book-open',
            self::HadithQuote => 'heroicon-o-chat-bubble-bottom-center-text',
            self::MotivationalQuote => 'heroicon-o-sparkles',
            self::DidYouKnow => 'heroicon-o-light-bulb',
            self::IslamicFaq => 'heroicon-o-question-mark-circle',
            self::IslamicComic => 'heroicon-o-photo',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::QuranQuote => 'emerald',
            self::HadithQuote => 'amber',
            self::MotivationalQuote => 'sky',
            self::DidYouKnow => 'violet',
            self::IslamicFaq => 'rose',
            self::IslamicComic => 'indigo',
        };
    }
}
