<?php

namespace App\Enums;

use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Speaker;

enum ContributionSubjectType: string
{
    case Event = 'event';
    case Institution = 'institution';
    case Speaker = 'speaker';
    case Reference = 'reference';

    public function publicRouteSegment(): string
    {
        return match ($this) {
            self::Event => 'majlis',
            self::Institution => 'institusi',
            self::Speaker => 'penceramah',
            self::Reference => 'rujukan',
        };
    }

    public static function fromRouteSegment(string $routeSegment): ?self
    {
        return match ($routeSegment) {
            'event', 'majlis' => self::Event,
            'institution', 'institusi' => self::Institution,
            'speaker', 'penceramah' => self::Speaker,
            'reference', 'rujukan' => self::Reference,
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    public static function publicRouteSegments(): array
    {
        return array_map(
            static fn (self $subjectType): string => $subjectType->publicRouteSegment(),
            self::cases(),
        );
    }

    /**
     * @return class-string<Event|Institution|Speaker|Reference>
     */
    public function modelClass(): string
    {
        return match ($this) {
            self::Event => Event::class,
            self::Institution => Institution::class,
            self::Speaker => Speaker::class,
            self::Reference => Reference::class,
        };
    }
}
