<?php

namespace App\Enums;

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

    public function label(): string
    {
        return match ($this) {
            self::Institution => __('Institution'),
            self::Person => __('Person'),
            self::Event => __('Event'),
            self::Reference => __('Reference'),
        };
    }

    public function publicRouteSegment(): string
    {
        return match ($this) {
            self::Institution => 'institusi',
            self::Person => 'penceramah',
            self::Event => 'majlis',
            self::Reference => 'rujukan',
        };
    }

    public static function fromRouteSegment(string $routeSegment): ?self
    {
        return match ($routeSegment) {
            'institution', 'institusi' => self::Institution,
            'person', 'penceramah' => self::Person,
            'event', 'majlis' => self::Event,
            'reference', 'rujukan' => self::Reference,
            default => null,
        };
    }

    public function isClaimable(): bool
    {
        return in_array($this, self::claimableCases(), true);
    }

    /**
     * @return list<self>
     */
    public static function claimableCases(): array
    {
        return [
            self::Institution,
            self::Person,
        ];
    }

    /**
     * @return list<string>
     */
    public static function claimableRouteSegments(): array
    {
        return array_map(
            static fn (self $subjectType): string => $subjectType->publicRouteSegment(),
            self::claimableCases(),
        );
    }

    public function modelClass(): string
    {
        return match ($this) {
            self::Institution => Institution::class,
            self::Person => Person::class,
            self::Event => Event::class,
            self::Reference => Reference::class,
        };
    }

    /**
     * @throws ModelNotFoundException
     */
    public function resolveSubject(string $subjectId): Institution|Person|Event|Reference
    {
        $modelClass = $this->modelClass();
        $subject = $modelClass::query()->findOrFail($subjectId);

        if (
            ! $subject instanceof Institution &&
            ! $subject instanceof Person &&
            ! $subject instanceof Event &&
            ! $subject instanceof Reference
        ) {
            throw (new ModelNotFoundException)->setModel($modelClass, [$subjectId]);
        }

        return $subject;
    }
}
