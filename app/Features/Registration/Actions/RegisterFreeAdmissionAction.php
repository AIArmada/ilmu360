<?php

declare(strict_types=1);

namespace App\Features\Registration\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Actions\RegisterForFreeAction;
use AIArmada\Events\Models\Event as PackageEvent;
use AIArmada\Events\Models\EventRegistration;
use App\Features\Registration\Api\AdmissionRegistration;
use App\Platform\Models\MorphModelResolver;
use App\Shared\ShareTracking\ParticipationAttribution;
use Illuminate\Database\Eloquent\Collection;
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

        return DB::transaction(function () use (
            $attributionMetadata,
            $event,
            $participants,
            $registrant,
            $registrationClass,
            $request,
            $userClass,
            $options,
        ): array {
            $created = OwnerContext::withOwner(null, fn (): Collection => $this->registerForFree->execute(
                target: $event,
                participants: $participants,
                registrant: $registrant,
                options: $options,
            ));

            $httpRequest = $request instanceof Request ? $request : request();
            $actor = $registrant instanceof $userClass ? $registrant : null;

            /** @var list<array{registration_id: string, created: bool}> $registrations */
            $registrations = [];

            foreach ($created as $registration) {
                // The free-registration authority returns pre-existing rows
                // on idempotent replay. Only transitions created by this
                // call freeze a decision; earlier unattributed/noop
                // registrations can never earn later through a new cookie.
                $freshlyCreated = $registration->wasRecentlyCreated;

                if (! $registration instanceof $registrationClass) {
                    $registration = $registrationClass::query()
                        ->whereKey($registration->getKey())
                        ->firstOrFail();
                }

                /** @var EventRegistration $registration */
                if ($freshlyCreated) {
                    $registration->refresh();

                    $this->attribution->recordFreeRegistration(
                        registrationId: (string) $registration->getKey(),
                        eventId: (string) $event->getKey(),
                        actorId: $actor?->getKey() !== null ? (string) $actor->getKey() : null,
                        guest: $actor === null,
                        request: $httpRequest,
                        businessMetadata: $attributionMetadata,
                    );
                }

                $registrations[] = [
                    'registration_id' => (string) $registration->getKey(),
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
