<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\EntryPoints\Checkout;

use AIArmada\Checkout\Data\StepResult;
use AIArmada\Checkout\Models\CheckoutSession;
use AIArmada\Checkout\Steps\AbstractCheckoutStep;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventSession;
use App\Features\Events\Api\EventScheduleReads;
use App\Features\Registration\Api\RegistrationLookup;
use App\Features\Registration\Api\Data\RegistrationSnapshot;
use App\Features\ShareTracking\Api\Data\ShareSubject;
use App\Features\ShareTracking\Api\Enums\ShareSubjectType;
use App\Features\ShareTracking\Api\OutcomeCorrectionSnapshot;
use App\Features\ShareTracking\Api\ShareOutcomeBusinessKey;
use App\Features\ShareTracking\Api\ShareOutcomeReversals;
use App\Features\ShareTracking\Api\ShareOutcomeType;
use App\Features\ShareTracking\Services\AffiliatesShareTrackingService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Throwable;

final class RecordCheckoutShareProvenanceStep extends AbstractCheckoutStep
{
    public function __construct(
        private readonly AffiliatesShareTrackingService $shareTracking,
        private readonly ShareOutcomeReversals $reverseShareOutcome,
    ) {}

    public function getIdentifier(): string
    {
        return 'record_checkout_share_provenance';
    }

    public function getName(): string
    {
        return 'Record Checkout Share Provenance';
    }

    /**
     * @return array<string>
     */
    public function getDependencies(): array
    {
        return ['create_event_registrations'];
    }

    public function handle(CheckoutSession $session): StepResult
    {
        $provenance = data_get($session->payment_data ?? [], 'share_provenance');
        $stepData = $session->getStepData('create_event_registrations');
        $registrationIds = $this->stringList($stepData['registration_ids'] ?? []);

        if ($registrationIds === []) {
            return $this->skipped('No event registrations to attribute.');
        }

        $intentValid = is_array($provenance)
            && ($provenance['intent_id'] ?? null) === (string) $session->getKey();

        $attributionId = null;
        $occurredAt = null;

        if ($intentValid) {
            $candidate = $provenance['attribution_id'] ?? null;
            $attributionId = is_string($candidate) && Str::isUuid($candidate) ? $candidate : null;
            $occurredAt = $this->trustedOccurredAt($provenance['occurred_at'] ?? null, $session);
        }

        $purchaserId = $this->purchaserUserId($session);
        $recorded = [];
        $failClosed = 0;

        foreach ($registrationIds as $registrationId) {
            $registration = app(RegistrationLookup::class)->findRegistration($registrationId);

            if (! $registration instanceof RegistrationSnapshot) {
                continue;
            }

            if ($session->order_id === null
                || (string) $registration->externalOrderId !== (string) $session->order_id) {
                continue;
            }

            $actor = $this->participantUser($registration);
            $identity = app(EventScheduleReads::class)->shareIdentityById($registration->eventId);
            $subject = $identity !== null
                ? ShareSubject::for(ShareSubjectType::Event->value, $identity['id'], $identity['title'])
                : null;
            $effectiveAttributionId = $attributionId;
            $effectiveOccurredAt = $occurredAt ?? $this->fallbackOccurredAt($session, $registration);

            if (! $intentValid || $this->isScopeCancelled($registration)) {
                $effectiveAttributionId = null;
                $failClosed++;
            }

            $this->shareTracking->recordCheckoutInheritedOutcome(
                type: ShareOutcomeType::EventRegistration,
                businessKey: ShareOutcomeBusinessKey::eventRegistration($registration->id),
                persistedAttributionId: $effectiveAttributionId,
                subject: $subject,
                actor: $actor,
                metadata: [
                    'registration_id' => $registration->id,
                    'guest' => ! $actor instanceof User,
                    'via_checkout' => true,
                    'purchaser_user_id' => $purchaserId,
                ],
                occurredAt: $effectiveOccurredAt,
            );

            $recorded[] = $registration->id;
        }

        if ($recorded === []) {
            return $this->skipped('No event registrations to attribute.');
        }

        $session->setStepData($this->getIdentifier(), ['registration_ids' => $recorded]);

        if (! $intentValid || $failClosed > 0) {
            return $this->success(
                sprintf('%d checkout registrations frozen unattributed.', count($recorded)),
                ['registration_ids' => $recorded],
            );
        }

        return $this->success(
            sprintf('%d checkout registrations attributed.', count($recorded)),
            ['registration_ids' => $recorded],
        );
    }

    public function compensate(CheckoutSession $session): StepResult
    {
        $stepData = $session->getStepData($this->getIdentifier());
        $registrationIds = $this->stringList($stepData['registration_ids'] ?? []);
        $errors = [];
        $reversed = 0;

        foreach ($registrationIds as $registrationId) {
            try {
                $correction = $this->reverseShareOutcome->reverse(
                    ShareOutcomeBusinessKey::eventRegistration($registrationId),
                    'checkout_compensated',
                );

                if ($correction instanceof OutcomeCorrectionSnapshot) {
                    $reversed++;
                }
            } catch (Throwable $e) {
                $errors[$registrationId] = $e->getMessage() !== ''
                    ? $e->getMessage()
                    : $e::class;
            }
        }

        if ($errors !== []) {
            return $this->failed('Checkout share provenance compensation failed.', $errors);
        }

        return $this->compensated(
            'Checkout share provenance compensated.',
            [
                'registration_ids' => $registrationIds,
                'reversed' => $reversed,
            ],
        );
    }

    private function trustedOccurredAt(mixed $value, CheckoutSession $session): CarbonInterface
    {
        if (is_string($value) && $value !== '') {
            try {
                return CarbonImmutable::parse($value);
            } catch (Throwable) {
            }
        }

        $createdAt = $session->created_at;

        if ($createdAt instanceof CarbonInterface) {
            return CarbonImmutable::createFromInterface($createdAt);
        }

        throw new \InvalidArgumentException('Checkout share intent carries no trusted occurrence timestamp.');
    }

    private function fallbackOccurredAt(CheckoutSession $session, RegistrationSnapshot $registration): CarbonInterface
    {
        $createdAt = $session->created_at;

        if ($createdAt instanceof CarbonInterface) {
            return CarbonImmutable::createFromInterface($createdAt);
        }

        $registeredAt = $registration->createdAt;

        if (is_string($registeredAt) && $registeredAt !== '') {
            return CarbonImmutable::parse($registeredAt);
        }

        throw new \InvalidArgumentException('Checkout share intent carries no trusted occurrence timestamp.');
    }

    private function isScopeCancelled(RegistrationSnapshot $registration): bool
    {
        if (app(EventScheduleReads::class)->eventStatus($registration->eventId) === 'cancelled') {
            return true;
        }

        if (is_string($registration->eventOccurrenceId) && $registration->eventOccurrenceId !== '') {
            $occurrence = EventOccurrence::query()->whereKey($registration->eventOccurrenceId)->first();

            if ($occurrence !== null && (string) $occurrence->status === 'cancelled') {
                return true;
            }
        }

        if (is_string($registration->eventSessionId) && $registration->eventSessionId !== '') {
            $session = EventSession::query()->whereKey($registration->eventSessionId)->first();

            if ($session !== null && (string) $session->status === 'cancelled') {
                return true;
            }
        }

        return in_array($registration->status, ['cancelled', 'rejected'], true);
    }

    private function purchaserUserId(CheckoutSession $session): ?string
    {
        $actor = data_get($session->payment_data ?? [], 'checkout_actor');

        if (! is_array($actor)) {
            return null;
        }

        $type = $actor['type'] ?? null;
        $id = $actor['id'] ?? null;

        if (! is_string($id) || $id === '') {
            return null;
        }

        if ($type !== (new User)->getMorphClass() && $type !== User::class) {
            return null;
        }

        return $id;
    }

    private function participantUser(RegistrationSnapshot $registration): ?User
    {
        return $registration->primaryParticipantUserId !== null
            ? User::query()->whereKey($registration->primaryParticipantUserId)->first()
            : null;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $item): string => (string) $item, $value),
            static fn (string $item): bool => $item !== '',
        ));
    }
}
