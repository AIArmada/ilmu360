<?php

namespace App\Enums;

enum EventTaxonomyCode: string
{
    case Domain = 'domain';
    case Discipline = 'discipline';
    case Source = 'source';
    case Issue = 'issue';

    public function label(): string
    {
        return match ($this) {
            self::Domain => __('Domain'),
            self::Discipline => __('Discipline'),
            self::Source => __('Source'),
            self::Issue => __('Issue'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Domain => __('Core Islamic knowledge areas (Aqidah, Syariah, Akhlak)'),
            self::Discipline => __('Specific fields of study (Tafsir, Sirah, Fiqh, etc.)'),
            self::Source => __('Reference sources (Quran, Hadith, Turath, etc.)'),
            self::Issue => __('Contemporary themes/topics (Rasuah, Kepimpinan, etc.)'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Domain => 'primary',
            self::Discipline => 'info',
            self::Source => 'success',
            self::Issue => 'warning',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Domain => 'heroicon-o-academic-cap',
            self::Discipline => 'heroicon-o-book-open',
            self::Source => 'heroicon-o-document-text',
            self::Issue => 'heroicon-o-exclamation-triangle',
        };
    }
}
