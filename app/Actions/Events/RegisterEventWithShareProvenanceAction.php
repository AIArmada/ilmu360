<?php

declare(strict_types=1);

namespace App\Actions\Events;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Actions\RegisterForFreeAction;
use App\Enums\DawahShareOutcomeType;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use App\Services\ShareTrackingService;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class RegisterEventWithShareProvenanceAction
{
    use AsAction;

    public function __construct(
        private RegisterForFreeAction $registerForFree,
        private ShareTrackingService $shareTracking,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $participants
     * @param  array<string, mixed>  $options
     * @return Collection<int, Registration>
     */
    public function handle(
        Event $target,
        array $participants,
        ?Model $registrant,
        array $options = [],
        ?Request $request = null,
    ): Collection {
        /** @var Collection<int, Registration> $registrations */
        $registrations = DB::transaction(function () use ($target, $participants, $registrant, $options, $request): Collection {
            $created = OwnerContext::withOwner(null, fn (): Collection => $this->registerForFree->execute(
                target: $target,
                participants: $participants,
                registrant: $registrant,
                options: $options,
            ));

            foreach ($created as $registration) {
                // The free-registration authority returns pre-existing rows
                // on idempotent replay. Only transitions created by this
                // call freeze a decision; earlier unattributed/noop
                // registrations can never earn later through a new cookie.
                $freshlyCreated = $registration->wasRecentlyCreated;

                $appRegistration = $registration instanceof Registration
                    ? $registration
                    : Registration::query()->whereKey($registration->getKey())->firstOrFail();

                if (! $freshlyCreated) {
                    continue;
                }

                $appRegistration->refresh();

                $actor = $registrant instanceof User ? $registrant : null;

                $this->shareTracking->recordOutcome(
                    type: DawahShareOutcomeType::EventRegistration,
                    businessKey: ShareOutcomeBusinessKey::eventRegistration((string) $appRegistration->getKey()),
                    subject: $target,
                    actor: $actor,
                    request: $request ?? request(),
                    metadata: [
                        'registration_id' => (string) $appRegistration->getKey(),
                        'guest' => ! $actor instanceof User,
                    ],
                    occurredAt: $appRegistration->created_at ?? now(),
                );
            }

            /** @var array<int, Registration> $mapped */
            $mapped = $created->map(static fn (Model $registration): Registration => $registration instanceof Registration
                ? $registration
                : Registration::query()->whereKey($registration->getKey())->firstOrFail())->all();

            /** @var Collection<int, Registration> $result */
            $result = new Collection($mapped);

            return $result;
        });

        return $registrations;
    }
}
