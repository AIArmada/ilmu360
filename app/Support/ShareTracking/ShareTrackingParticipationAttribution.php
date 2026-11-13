<?php

declare(strict_types=1);

namespace App\Support\ShareTracking;

use App\Actions\ShareTracking\ReverseShareOutcomeAction;
use App\Shared\ShareTracking\ShareOutcomeBusinessKey;
use App\Shared\ShareTracking\ShareOutcomeType;
use App\Models\EventCheckin;
use App\Models\User;
use App\Platform\Models\MorphModelResolver;
use App\Services\ShareTrackingService;
use App\Shared\ShareTracking\ParticipationAttribution;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Root adapter for Registration's share-attribution boundary.
 *
 * Share tracking stays root-owned during the Registration migration; this
 * adapter keeps the canonical business keys, metadata keys and occurrence
 * times unchanged so the feature does not import the tracking service or its
 * enum.
 */
final readonly class ShareTrackingParticipationAttribution implements ParticipationAttribution
{
    public function __construct(
        private ShareTrackingService $shareTracking,
        private ReverseShareOutcomeAction $reverseShareOutcome,
    ) {}

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
        $subject = $this->findEvent($eventId);
        $registration = MorphModelResolver::resolve('registration')::query()->find($registrationId);
        $actor = $this->findActor($actorId);

        $metadata = [
            'registration_id' => $registrationId,
            'guest' => $guest,
        ];

        foreach ($businessMetadata as $key => $value) {
            if (! array_key_exists($key, $metadata)) {
                $metadata[$key] = $value;
            }
        }

        $createdAt = $registration?->getAttribute('created_at');

        $this->shareTracking->recordOutcome(
            type: ShareOutcomeType::EventRegistration,
            businessKey: ShareOutcomeBusinessKey::eventRegistration($registrationId),
            subject: $subject,
            actor: $actor,
            request: $request instanceof Request ? $request : request(),
            metadata: $metadata,
            occurredAt: $createdAt instanceof CarbonInterface ? $createdAt : now(),
        );
    }

    public function recordCheckIn(
        string $checkinId,
        string $eventId,
        ?string $actorId,
        ?object $request = null,
    ): void {
        $checkin = $this->findCheckin($checkinId);

        $this->shareTracking->recordOutcome(
            type: ShareOutcomeType::EventCheckin,
            businessKey: ShareOutcomeBusinessKey::eventCheckin($checkinId),
            subject: $this->findEvent($eventId),
            actor: $this->findActor($actorId),
            request: $request instanceof Request ? $request : request(),
            metadata: $this->checkinMetadata($checkin),
            occurredAt: $checkin->created_at ?? now(),
        );
    }

    public function recordInheritedCheckIn(
        string $checkinId,
        string $eventId,
        ?string $actorId,
        string $registrationId,
    ): void {
        $checkin = $this->findCheckin($checkinId);

        $this->shareTracking->recordInheritedOutcome(
            type: ShareOutcomeType::EventCheckin,
            businessKey: ShareOutcomeBusinessKey::eventCheckin($checkinId),
            sourceBusinessKey: ShareOutcomeBusinessKey::eventRegistration($registrationId),
            subject: $this->findEvent($eventId),
            actor: $this->findActor($actorId),
            metadata: $this->checkinMetadata($checkin),
            occurredAt: $checkin->created_at ?? now(),
        );
    }

    public function recordStaffCheckIn(
        string $checkinId,
        string $eventId,
        string $staffId,
        string $registrationId,
    ): void {
        $checkin = $this->findCheckin($checkinId);

        $this->shareTracking->recordInheritedOutcome(
            type: ShareOutcomeType::EventCheckin,
            businessKey: ShareOutcomeBusinessKey::eventCheckin($checkinId),
            sourceBusinessKey: ShareOutcomeBusinessKey::eventRegistration($registrationId),
            subject: $this->findEvent($eventId),
            metadata: [
                'registration_id' => $registrationId,
                'staff_assisted' => true,
                'verified_by_user_id' => $staffId,
            ],
            occurredAt: $checkin->created_at ?? now(),
        );
    }

    public function reverseCheckIn(
        string $checkinId,
        string $reason,
        ?string $actorId = null,
    ): void {
        $this->reverseShareOutcome->handle(
            ShareOutcomeBusinessKey::eventCheckin($checkinId),
            $reason,
            $this->findActor($actorId),
        );
    }

    private function findEvent(string $eventId): ?Model
    {
        return MorphModelResolver::resolve('event')::query()->find($eventId);
    }

    private function findActor(?string $actorId): ?User
    {
        return $actorId !== null ? User::query()->find($actorId) : null;
    }

    private function findCheckin(string $checkinId): EventCheckin
    {
        return EventCheckin::query()->findOrFail($checkinId);
    }

    /**
     * @return array{checkin_id: string, event_registration_id: string|null, check_in_source: string|null}
     */
    private function checkinMetadata(EventCheckin $checkin): array
    {
        return [
            'checkin_id' => $checkin->getKey(),
            'event_registration_id' => $checkin->event_registration_id,
            'check_in_source' => $checkin->check_in_source,
        ];
    }
}
