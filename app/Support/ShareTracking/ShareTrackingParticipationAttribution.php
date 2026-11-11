<?php

declare(strict_types=1);

namespace App\Support\ShareTracking;

use App\Enums\ShareOutcomeType;
use App\Models\User;
use App\Platform\Models\MorphModelResolver;
use App\Services\ShareTrackingService;
use App\Shared\ShareTracking\ParticipationAttribution;
use Illuminate\Http\Request;

/**
 * Root adapter for Registration's share-attribution boundary.
 *
 * Share tracking stays root-owned during the Registration migration; this
 * adapter keeps the canonical business key and metadata keys unchanged so the
 * feature does not import the tracking service or its enum.
 */
final readonly class ShareTrackingParticipationAttribution implements ParticipationAttribution
{
    public function __construct(private ShareTrackingService $shareTracking) {}

    /**
     * @param  array<string, mixed>  $businessMetadata
     */
    public function recordFreeRegistration(
        string $registrationId,
        string $eventId,
        ?string $actorId,
        bool $guest,
        ?object $request = null,
        array $businessMetadata = [],
    ): void {
        $eventClass = MorphModelResolver::resolve('event');
        $registrationClass = MorphModelResolver::resolve('registration');

        $subject = $eventClass::query()->find($eventId);
        $registration = $registrationClass::query()->find($registrationId);
        $actor = $actorId !== null ? User::query()->find($actorId) : null;

        $metadata = [
            'registration_id' => $registrationId,
            'guest' => $guest,
        ];

        foreach ($businessMetadata as $key => $value) {
            if (! array_key_exists($key, $metadata)) {
                $metadata[$key] = $value;
            }
        }

        $this->shareTracking->recordOutcome(
            type: ShareOutcomeType::EventRegistration,
            businessKey: ShareOutcomeBusinessKey::eventRegistration($registrationId),
            subject: $subject,
            actor: $actor instanceof User ? $actor : null,
            request: $request instanceof Request ? $request : request(),
            metadata: $metadata,
            occurredAt: $registration?->created_at ?? now(),
        );
    }
}
