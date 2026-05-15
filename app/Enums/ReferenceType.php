<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ReferenceType: string implements HasLabel
{
    case Book = 'book';
    case Article = 'article';
    case Video = 'video';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Book => __('Book'),
            self::Article => __('Article'),
            self::Video => __('Video'),
            self::Other => __('Other'),
        };
    }
}
