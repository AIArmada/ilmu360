<?php

declare(strict_types=1);

namespace App\Features\Events\Queries;

use AIArmada\Events\Features\Catalog\Models\EventOccurrence;
use AIArmada\Events\Features\Catalog\Models\EventSession;
use App\Features\Events\Api\PublicScheduleSlug;
use App\Features\Events\Models\Event;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Resolve public occurrence and session page identity inside the Events owner.
 */
final readonly class PublicScheduleDetailResolver
{
    public function occurrence(Event $event, string $slug): ?EventOccurrence
    {
        $event->loadMissing(['occurrences' => static function (Relation $query): void {
            $query
                ->whereIn('status', Event::PUBLIC_SCHEDULE_STATUSES)
                ->whereIn('visibility', Event::PUBLIC_SCHEDULE_VISIBILITIES)
                ->orderBy('starts_at')
                ->orderBy('created_at')
                ->orderBy('id');
        }]);

        /** @var EventOccurrence|null $occurrence */
        $occurrence = $event->occurrences->first(
            static fn (EventOccurrence $candidate): bool => PublicScheduleSlug::occurrence(
                slug: (string) $candidate->slug,
                title: $candidate->title,
                id: (string) $candidate->getKey(),
            ) === $slug,
        );

        return $occurrence;
    }

    public function session(EventOccurrence $occurrence, string $slug): ?EventSession
    {
        $occurrence->loadMissing(['sessions' => static function (Relation $query): void {
            $query
                ->whereIn('status', Event::PUBLIC_SCHEDULE_STATUSES)
                ->whereIn('visibility', Event::PUBLIC_SCHEDULE_VISIBILITIES)
                ->orderBy('sort_order')
                ->orderBy('starts_at')
                ->orderBy('created_at')
                ->orderBy('id');
        }]);

        /** @var EventSession|null $session */
        $session = $occurrence->sessions->first(
            static fn (EventSession $candidate): bool => PublicScheduleSlug::session(
                slug: (string) $candidate->slug,
                title: $candidate->title,
                id: (string) $candidate->getKey(),
            ) === $slug,
        );

        return $session;
    }
}
