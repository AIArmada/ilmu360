<?php

declare(strict_types=1);

namespace App\Shared\Reports;

/**
 * Stable report subject vocabulary shared by routes and report entry points.
 *
 * Public route segments mirror the existing public subject URLs so report
 * links keep working while Contributions still owns its transition enum.
 */
enum ReportSubjectType: string
{
    case Event = 'event';
    case Institution = 'institution';
    case Person = 'person';
    case Reference = 'reference';
    case DonationChannel = 'donation_channel';

    public function publicRouteSegment(): ?string
    {
        return match ($this) {
            self::Event => 'majlis',
            self::Institution => 'institusi',
            self::Person => 'penceramah',
            self::Reference => 'rujukan',
            self::DonationChannel => null,
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
        $segments = [];

        foreach (self::cases() as $type) {
            $segment = $type->publicRouteSegment();

            if ($segment !== null) {
                $segments[] = $segment;
            }
        }

        return $segments;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_map(
            static fn (self $type): string => $type->value,
            self::cases(),
        );
    }
}
