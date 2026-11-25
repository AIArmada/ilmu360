<?php

declare(strict_types=1);

namespace App\Features\Registration\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Features\Catalog\Models\Event as PackageEvent;
use AIArmada\Events\Features\Registrations\Actions\RegisterForFreeAction;
use App\Features\Registration\Api\AdmissionRegistration;
use App\Features\ShareTracking\Api\ParticipationAttribution;
use App\Platform\Models\MorphModelResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Owns the application's free-admission write path.
 *
 * The vendor RegisterForFreeAction remains the canonical registration writer
 * (registrations, participants, answers and items); this action is the
 * app-side aggregate entry point that resolves caller identities through
 * morph aliases, keeps the admission transaction, and freezes share
 * attribution only for rows created by this call.
 */
final readonly class RegisterFreeAdmissionAction implements AdmissionRegistration
{
    use AsAction;

    public function __construct(
        private RegisterForFreeAction $registerForFree,
        private ParticipationAttribution $attribution,
    ) {}

    public function hasActiveForUser(string $eventId, string $registrantId): bool
    {
        $registrationClass = MorphModelResolver::resolve('registration');
        $userClass = MorphModelResolver::resolve('user');
        $user = new $userClass;

        return $registrationClass::query()
            ->where('registrant_type', $user->getMorphClass())
            ->where('registrant_id', $registrantId)
            ->where('event_id', $eventId)
            ->where('status', '!=', 'cancelled')
            ->exists();
    }

    /**
     * @param  array<int, array<string, mixed>>  $participants
     * @param  array<string, mixed>  $options
     * @return array{
     *     registrations: list<array{registration_id: string, created: bool}>,
     *     primary_registration_id: string|null
     * }
     */
    public function registerFree(
        string $eventId,
        array $participants,
        ?string $registrantId,
        array $options = [],
        ?object $request = null,
    ): array {
        $eventClass = MorphModelResolver::resolve('event');
        $registrationClass = MorphModelResolver::resolve('registration');
        $userClass = MorphModelResolver::resolve('user');

        /** @var PackageEvent $event */
        $event = $eventClass::query()->findOrFail($eventId);

        /** @var Model|null $registrant */
        $registrant = $registrantId !== null
            ? $userClass::query()->findOrFail($registrantId)
            : null;

        $attributionMetadata = isset($options['attribution_metadata']) && is_array($options['attribution_metadata'])
            ? $options['attribution_metadata']
            : [];
        unset($options['attribution_metadata']);

        $idempotencyKey = isset($options['idempotency_key']) && is_string($options['idempotency_key'])
            ? $options['idempotency_key']
            : null;

        return DB::transaction(function () use (
            $attributionMetadata,
            $event,
            $idempotencyKey,
            $participants,
            $registrant,
            $registrationClass,
            $request,
            $userClass,
            $options,
        ): array {
            // Idempotent replays return the rows recorded under the same key.
            // Resolving the pre-existing ids first lets this call report
            // created vs replayed rows without hydrating models.
            $existingIds = $idempotencyKey === null
                ? []
                : array_flip($registrationClass::query()
                    ->where('event_id', $event->getKey())
                    ->whereNull('event_occurrence_id')
                    ->whereNull('event_session_id')
                    ->where('idempotency_key', $idempotencyKey)
                    ->pluck('id')
                    ->map(static fn (mixed $id): string => (string) $id)
                    ->all());

            $createdIds = OwnerContext::withOwner(null, fn (): array => $this->registerForFree->execute(
                scopeType: 'event',
                scopeId: (string) $event->getKey(),
                participants: $participants,
                registrantType: $registrant instanceof Model ? $registrant->getMorphClass() : null,
                registrantId: $registrant instanceof Model ? (string) $registrant->getKey() : null,
                options: $options,
            ));

            $httpRequest = $request instanceof Request ? $request : request();
            $actor = $registrant instanceof $userClass ? $registrant : null;

            /** @var list<array{registration_id: string, created: bool}> $registrations */
            $registrations = [];

            foreach ($createdIds as $registrationId) {
                // The free-registration authority returns pre-existing rows
                // on idempotent replay. Only transitions created by this
                // call freeze a decision; earlier unattributed/noop
                // registrations can never earn later through a new cookie.
                $freshlyCreated = ! isset($existingIds[$registrationId]);

                if ($freshlyCreated) {
                    $this->attribution->recordFreeRegistration(
                        registrationId: $registrationId,
                        eventId: (string) $event->getKey(),
                        actorId: $actor?->getKey() !== null ? (string) $actor->getKey() : null,
                        guest: ! $actor instanceof Model,
                        request: $httpRequest,
                        businessMetadata: $attributionMetadata,
                    );
                }

                $registrations[] = [
                    'registration_id' => $registrationId,
                    'created' => $freshlyCreated,
                ];
            }

            return [
                'registrations' => $registrations,
                'primary_registration_id' => $registrations[0]['registration_id'] ?? null,
            ];
        });
    }
}
