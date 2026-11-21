<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Services;

use App\Features\Events\Api\EventScheduleReads;
use App\Features\Registration\Api\Data\CheckInSourceFacts;
use App\Features\Registration\Api\Orm\RegistrationLookup;
use App\Features\Registration\Models\EventCheckin;
use App\Features\Registration\Models\Registration;
use App\Features\ShareTracking\Api\Data\ShareSubject;
use App\Features\ShareTracking\Api\Enums\ShareSubjectType;
use App\Features\ShareTracking\Api\ParticipationAttribution;
use App\Features\ShareTracking\Api\ShareOutcomeBusinessKey;
use App\Features\ShareTracking\Api\ShareOutcomeReversals;
use App\Features\ShareTracking\Api\ShareOutcomeType;
use App\Models\User;
use App\Shared\ShareTracking\ShareRequestContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

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
        private AffiliatesShareTrackingService $shareTracking,
        private ShareOutcomeReversals $reverseShareOutcome,
        private RegistrationLookup $registrationLookup,
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
        $subject = $this->eventSubject($eventId);
        $registration = $this->registrationLookup->findRegistration($registrationId);
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

        $createdAt = $registration instanceof Registration ? $registration->getAttribute('created_at') : null;

        $this->shareTracking->recordOutcome(
            type: ShareOutcomeType::EventRegistration,
            businessKey: ShareOutcomeBusinessKey::eventRegistration($registrationId),
            subject: $subject,
            actor: $actor,
            request: ShareRequestContext::fromRequest($request instanceof Request ? $request : request()),
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
            subject: $this->eventSubject($eventId),
            actor: $this->findActor($actorId),
            request: ShareRequestContext::fromRequest($request instanceof Request ? $request : request()),
            metadata: $this->checkinMetadata($checkin),
            occurredAt: $this->occurredAt($checkin),
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
            subject: $this->eventSubject($eventId),
            actor: $this->findActor($actorId),
            metadata: $this->checkinMetadata($checkin),
            occurredAt: $this->occurredAt($checkin),
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
            subject: $this->eventSubject($eventId),
            metadata: [
                'registration_id' => $registrationId,
                'staff_assisted' => true,
                'verified_by_user_id' => $staffId,
            ],
            occurredAt: $this->occurredAt($checkin),
        );
    }

    public function reverseCheckIn(
        string $checkinId,
        string $reason,
        ?string $actorId = null,
    ): void {
        $actor = $this->findActor($actorId);

        $this->reverseShareOutcome->reverse(
            ShareOutcomeBusinessKey::eventCheckin($checkinId),
            $reason,
            $actor instanceof User ? (string) $actor->getKey() : null,
        );
    }

    private function eventSubject(string $eventId): ?ShareSubject
    {
        $identity = app(EventScheduleReads::class)->shareIdentityById($eventId);

        return $identity !== null
            ? ShareSubject::for(ShareSubjectType::Event->value, $identity['id'], $identity['title'])
            : null;
    }

    private function findActor(?string $actorId): ?User
    {
        return $actorId !== null ? User::query()->find($actorId) : null;
    }

    private function findCheckin(string $checkinId): CheckInSourceFacts
    {
        return $this->registrationLookup->checkInSourceFacts($checkinId)
            ?? throw (new ModelNotFoundException)->setModel(EventCheckin::class, [$checkinId]);
    }

    private function occurredAt(CheckInSourceFacts $checkin): CarbonInterface
    {
        return $checkin->createdAt !== null
            ? Carbon::parse($checkin->createdAt)
            : now();
    }

    /**
     * @return array{checkin_id: string, event_registration_id: string|null, check_in_source: string|null}
     */
    private function checkinMetadata(CheckInSourceFacts $checkin): array
    {
        return [
            'checkin_id' => $checkin->id,
            'event_registration_id' => $checkin->eventRegistrationId,
            'check_in_source' => $checkin->checkInSource,
        ];
    }
}
