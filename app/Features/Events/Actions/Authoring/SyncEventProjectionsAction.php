<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Authoring;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Features\Events\Api\EventProjections;
use App\Features\Events\Support\EventModel;
use Illuminate\Database\Eloquent\Model;

/**
 * Owns event_links/event_audiences/event_audience_profiles writes behind the
 * legacy Event::saved projection hook.
 */
final readonly class SyncEventProjectionsAction implements EventProjections
{
    private const array LINK_TYPE_MAP = [
        'live_url' => 'streaming',
        'event_url' => 'external',
        'recording_url' => 'recording',
    ];

    /**
     * @param  array<string, string|null>  $writes
     */
    public function syncLinks(string $eventId, array $writes): void
    {
        if ($writes === []) {
            return;
        }

        $event = $this->resolveEvent($eventId);

        foreach ($writes as $field => $value) {
            $linkType = self::LINK_TYPE_MAP[$field] ?? null;

            if ($linkType === null) {
                continue;
            }

            if ($value !== null && $value !== '') {
                $event->links()->updateOrCreate(
                    ['event_id' => (string) $event->getKey(), 'link_type' => $linkType],
                    ['url' => $value, 'visibility' => 'public'],
                );
            } else {
                $event->links()
                    ->where('link_type', $linkType)
                    ->delete();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $audienceWrites
     * @param  array<string, mixed>  $profileWrites
     */
    public function syncAudiences(string $eventId, array $audienceWrites, array $profileWrites): void
    {
        if ($audienceWrites === [] && $profileWrites === []) {
            return;
        }

        $event = $this->resolveEvent($eventId);

        OwnerContext::withOwner($event->getRelationValue('owner'), function () use ($event, $audienceWrites, $profileWrites): void {
            foreach ($audienceWrites as $type => $value) {
                match ($type) {
                    'gender' => $this->syncSingleAudience($event, 'gender', $value),
                    'age_group' => $this->syncAgeGroupAudience($event, $value),
                    'is_muslim_only' => $this->syncSingleAudience($event, 'religion', $value ? 'muslim_only' : null),
                    default => null,
                };
            }

            if ($profileWrites !== []) {
                $event->audienceProfiles()->updateOrCreate(
                    ['event_id' => $event->getKey()],
                    ['is_child_friendly' => $profileWrites['children_allowed'] ?? null],
                );
            }
        });
    }

    /** @return \App\Models\Event */
    private function resolveEvent(string $eventId): Model
    {
        return EventModel::query('event')->withoutOwnerScope()->findOrFail($eventId);
    }

    /** @param  \App\Models\Event  $event */
    private function syncSingleAudience(Model $event, string $type, mixed $value): void
    {
        if (! in_array($value, [null, '', false], true)) {
            $event->audiences()->updateOrCreate(
                ['event_id' => $event->getKey(), 'audience_type' => $type],
                ['value' => (string) $value],
            );
        } else {
            $event->audiences()
                ->where('audience_type', $type)
                ->delete();
        }
    }

    /** @param  \App\Models\Event  $event */
    private function syncAgeGroupAudience(Model $event, mixed $value): void
    {
        $event->audiences()
            ->where('audience_type', 'age_group')
            ->delete();

        if (in_array($value, [null, [], ''], true)) {
            return;
        }

        $values = is_array($value) ? $value : [$value];

        foreach (array_values($values) as $i => $v) {
            $event->audiences()->create([
                'event_id' => $event->getKey(),
                'audience_type' => 'age_group',
                'value' => (string) $v,
                'sort_order' => $i,
            ]);
        }
    }
}
