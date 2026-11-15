<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

enum EventImageCollection: string
{
    case Cover = 'cover';
    case Poster = 'poster';

    public function mediaLabel(): string
    {
        return match ($this) {
            self::Cover => 'Event Cover Image',
            self::Poster => 'Event Poster Image',
        };
    }
}
