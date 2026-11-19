<?php

declare(strict_types=1);

namespace App\Features\Membership\Api;

enum MembershipApplicationSubjectType: string
{
    case Institution = 'institution';
    case Person = 'person';

    public function membershipSubjectType(): MemberSubjectType
    {
        return MemberSubjectType::from($this->value);
    }

    public function publicRouteSegment(): string
    {
        return match ($this) {
            self::Institution => 'institusi',
            self::Person => 'penceramah',
        };
    }

    public static function fromPublicRouteSegment(string $routeSegment): ?self
    {
        return match ($routeSegment) {
            'institusi' => self::Institution,
            'penceramah' => self::Person,
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    public static function routeSegments(): array
    {
        return array_map(
            static fn (self $subjectType): string => $subjectType->publicRouteSegment(),
            self::cases(),
        );
    }
}
