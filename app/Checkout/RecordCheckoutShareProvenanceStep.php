<?php

declare(strict_types=1);

namespace App\Checkout;

use AIArmada\Checkout\Data\StepResult;
use AIArmada\Checkout\Models\CheckoutSession;
use AIArmada\Checkout\Steps\AbstractCheckoutStep;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventSession;
use App\Actions\ShareTracking\ReverseShareOutcomeAction;
use App\Enums\DawahShareOutcomeType;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use App\Services\ShareTrackingService;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

final class RecordCheckoutShareProvenanceStep extends AbstractCheckoutStep
{
    public function __construct(
        private readonly ShareTrackingService $shareTracking,
        private readonly ReverseShareOutcomeAction $reverseShareOutcome,
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
            $registration = Registration::query()->whereKey($registrationId)->first();

            if (! $registration instanceof Registration) {
                continue;
            }

            if ($session->order_id === null
                || (string) $registration->external_order_id !== (string) $session->order_id) {
                continue;
            }

            $actor = $this->participantUser($registration);
            $subject = Event::query()->whereKey($registration->event_id)->first();
            $effectiveAttributionId = $attributionId;
            $effectiveOccurredAt = $occurredAt ?? $this->fallbackOccurredAt($session, $registration);

            if (! $intentValid || $this->isScopeCancelled($registration)) {
                $effectiveAttributionId = null;
                $failClosed++;
            }

            $this->shareTracking->recordCheckoutInheritedOutcome(
                type: DawahShareOutcomeType::EventRegistration,
                businessKey: ShareOutcomeBusinessKey::eventRegistration((string) $registration->getKey()),
                persistedAttributionId: $effectiveAttributionId,
                subject: $subject instanceof Model ? $subject : null,
                actor: $actor,
                metadata: [
                    'registration_id' => (string) $registration->getKey(),
                    'guest' => ! $actor instanceof User,
                    'via_checkout' => true,
                    'purchaser_user_id' => $purchaserId,
                ],
                occurredAt: $effectiveOccurredAt,
            );

            $recorded[] = (string) $registration->getKey();
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
                $correction = $this->reverseShareOutcome->handle(
                    ShareOutcomeBusinessKey::eventRegistration($registrationId),
                    'checkout_compensated',
                    null,
                    null,
                );

                if ($correction !== null) {
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

    private function fallbackOccurredAt(CheckoutSession $session, Registration $registration): CarbonInterface
    {
        $createdAt = $session->created_at;

        if ($createdAt instanceof CarbonInterface) {
            return CarbonImmutable::createFromInterface($createdAt);
        }

        $registeredAt = $registration->created_at;

        if ($registeredAt instanceof CarbonInterface) {
            return CarbonImmutable::createFromInterface($registeredAt);
        }

        throw new \InvalidArgumentException('Checkout share intent carries no trusted occurrence timestamp.');
    }

    private function isScopeCancelled(Registration $registration): bool
    {
        $event = Event::query()->whereKey($registration->event_id)->first();

        if ($event instanceof Event && (string) $event->status === 'cancelled') {
            return true;
        }

        if (is_string($registration->event_occurrence_id) && $registration->event_occurrence_id !== '') {
            $occurrence = EventOccurrence::query()->whereKey($registration->event_occurrence_id)->first();

            if ($occurrence !== null && (string) $occurrence->status === 'cancelled') {
                return true;
            }
        }

        if (is_string($registration->event_session_id) && $registration->event_session_id !== '') {
            $session = EventSession::query()->whereKey($registration->event_session_id)->first();

            if ($session !== null && (string) $session->status === 'cancelled') {
                return true;
            }
        }

        return in_array($registration->statusValue(), ['cancelled', 'rejected'], true);
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

    private function participantUser(Registration $registration): ?User
    {
        $participant = $registration->participants()->where('is_primary', true)->first()
            ?? $registration->participants()->first();

        if ($participant === null) {
            return null;
        }

        $type = $participant->participant_type ?? null;
        $id = $participant->participant_id ?? null;

        if (! is_string($type) || ! is_string($id) || $id === '') {
            return null;
        }

        if ($type !== (new User)->getMorphClass() && $type !== User::class) {
            return null;
        }

        return User::query()->whereKey($id)->first();
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
