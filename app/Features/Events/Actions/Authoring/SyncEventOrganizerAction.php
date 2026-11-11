<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Authoring;

use AIArmada\Events\Models\EventInvolvement;
use AIArmada\Events\Models\EventRole;
use App\Features\Events\Support\EventModel;
use Illuminate\Support\Str;

/**
 * Owns the primary organizer involvement row behind Event::setPrimaryOrganizer.
 */
final readonly class SyncEventOrganizerAction
{
    public function handle(string $eventId, ?string $organizerType, ?string $organizerId): void
    {
        $event = EventModel::query('event')->withoutOwnerScope()->findOrFail($eventId);

        $involvement = EventInvolvement::query()
            ->where('event_id', $eventId)
            ->whereNull('event_occurrence_id')
            ->whereNull('event_session_id')
            ->where('role_code', 'organizer')
            ->where('is_primary', true)
            ->first();

        if ($organizerType === null || $organizerId === null) {
            $involvement?->delete();

            return;
        }

        $attributes = [
            'event_id' => $eventId,
            'event_occurrence_id' => null,
            'event_session_id' => null,
            'involveable_type' => $organizerType,
            'involveable_id' => $organizerId,
            'event_role_id' => EventRole::where('code', 'organizer')->value('id'),
            'role_code' => 'organizer',
            'status' => 'confirmed',
            'visibility' => 'public',
            'prominence' => 0,
            'is_featured' => false,
            'is_primary' => true,
            'sort_order' => 0,
        ];

        if ($involvement instanceof EventInvolvement) {
            $involvement->fill($attributes);

            if ($involvement->isDirty()) {
                $involvement->save();
            }

            return;
        }

        $involvement = new EventInvolvement($attributes);
        $involvement->{$involvement->getKeyName()} = (string) Str::uuid();
        $involvement->save();
    }
}
