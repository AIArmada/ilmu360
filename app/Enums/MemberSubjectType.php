<?php

namespace App\Enums;

use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Speaker;
use Illuminate\Database\Eloquent\ModelNotFoundException;

enum MemberSubjectType: string
{
    case Institution = 'institution';
    case Speaker = 'speaker';
    case Event = 'event';
    case Reference = 'reference';

    public function label(): string
    {
        return match ($this) {
            self::Institution => __('Institution'),
            self::Speaker => __('Speaker'),
            self::Event => __('Event'),
            self::Reference => __('Reference'),
        };
    }

    public function publicRouteSegment(): string
    {
        return match ($this) {
            self::Institution => 'institusi',
            self::Speaker => 'penceramah',
            self::Event => 'majlis',
            self::Reference => 'rujukan',
        };
    }

    public static function fromRouteSegment(string $routeSegment): ?self
    {
        return match ($routeSegment) {
            'institution', 'institusi' => self::Institution,
            'speaker', 'penceramah' => self::Speaker,
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
            self::Speaker,
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
            self::Speaker => Speaker::class,
            self::Event => Event::class,
            self::Reference => Reference::class,
        };
    }

    /**
     * @throws ModelNotFoundException
     */
    public function resolveSubject(string $subjectId): Institution|Speaker|Event|Reference
    {
        $modelClass = $this->modelClass();
        $subject = $modelClass::query()->findOrFail($subjectId);

        if (
            ! $subject instanceof Institution &&
            ! $subject instanceof Speaker &&
            ! $subject instanceof Event &&
            ! $subject instanceof Reference
        ) {
            throw (new ModelNotFoundException)->setModel($modelClass, [$subjectId]);
        }

        return $subject;
    }
}
