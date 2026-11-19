<?php

namespace App\Features\Membership\Api;

enum MemberSubjectType: string
{
    case Institution = 'institution';
    case Person = 'person';
    case Event = 'event';
    case Reference = 'reference';
    case Organization = 'organization';

    public function label(): string
    {
        return match ($this) {
            self::Institution => __('Institution'),
            self::Person => __('Person'),
            self::Event => __('Event'),
            self::Reference => __('Reference'),
            self::Organization => __('Organization'),
        };
    }

    public function publicRouteSegment(): string
    {
        return match ($this) {
            self::Institution => MembershipApplicationSubjectType::Institution->publicRouteSegment(),
            self::Person => MembershipApplicationSubjectType::Person->publicRouteSegment(),
            self::Event => 'majlis',
            self::Reference => 'rujukan',
            self::Organization => 'organisasi',
        };
    }

    public static function fromPublicRouteSegment(string $routeSegment): ?self
    {
        $membershipSubjectType = MembershipApplicationSubjectType::fromPublicRouteSegment($routeSegment);

        if ($membershipSubjectType instanceof MembershipApplicationSubjectType) {
            return self::from($membershipSubjectType->value);
        }

        return match ($routeSegment) {
            'majlis' => self::Event,
            'rujukan' => self::Reference,
            'organisasi' => self::Organization,
            default => null,
        };
    }

    public function supportsMembershipApplication(): bool
    {
        return MembershipApplicationSubjectType::tryFrom($this->value) instanceof MembershipApplicationSubjectType;
    }
}
