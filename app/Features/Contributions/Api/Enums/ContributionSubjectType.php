<?php

declare(strict_types=1);

namespace App\Features\Contributions\Api\Enums;

enum ContributionSubjectType: string
{
    case Event = 'event';
    case Institution = 'institution';
    case Person = 'person';
    case Reference = 'reference';

    public function publicRouteSegment(): string
    {
        return match ($this) {
            self::Event => 'majlis',
            self::Institution => 'institusi',
            self::Person => 'penceramah',
            self::Reference => 'rujukan',
        };
    }

    public static function fromRouteSegment(string $routeSegment): ?self
    {
        return match ($routeSegment) {
            'event', 'majlis' => self::Event,
            'institution', 'institusi' => self::Institution,
            'person', 'penceramah' => self::Person,
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
}
