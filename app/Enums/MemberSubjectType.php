<?php

namespace App\Enums;

use AIArmada\Organizations\Models\Organization;
use App\Features\Membership\Api\MembershipApplicationSubjectType;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Reference;
use Illuminate\Database\Eloquent\ModelNotFoundException;

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

    public function modelClass(): string
    {
        return match ($this) {
            self::Institution => Institution::class,
            self::Person => Person::class,
            self::Event => Event::class,
            self::Reference => Reference::class,
            self::Organization => Organization::class,
        };
    }

    /**
     * @throws ModelNotFoundException
     */
    public function resolveSubject(string $subjectId): Institution|Person|Event|Reference|Organization
    {
        $modelClass = $this->modelClass();
        $subject = $modelClass::query()->findOrFail($subjectId);

        if (
            ! $subject instanceof Institution &&
            ! $subject instanceof Person &&
            ! $subject instanceof Event &&
            ! $subject instanceof Reference
            && ! $subject instanceof Organization
        ) {
            throw (new ModelNotFoundException)->setModel($modelClass, [$subjectId]);
        }

        return $subject;
    }
}
