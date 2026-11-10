<?php

namespace App\Livewire\Concerns;

use AIArmada\Engagement\Models\Response;
use App\Actions\Events\Engagement\MarkEventGoingAction;
use App\Actions\Events\Engagement\RemoveEventGoingAction;
use App\Actions\ShareTracking\ReverseShareOutcomeAction;
use App\Enums\ShareOutcomeType;
use App\Models\Event;
use App\Models\User;
use App\Services\ShareTrackingService;
use App\Shared\Auth\IntendedRedirect;
use App\Shared\Engagement\EngagementReader;
use App\Support\ShareTracking\ShareOutcomeBusinessKey;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;

trait TogglesEventGoing
{
    abstract protected function eventGoingReturnUrl(): string;

    public function toggleGoing(string $eventId): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            $this->redirect(
                IntendedRedirect::loginUrl($this->eventGoingReturnUrl()),
                navigate: true,
            );

            return;
        }

        $event = Event::query()
            ->active()
            ->whereKey($eventId)
            ->first();

        if (! $event instanceof Event
            || ! in_array((string) $event->status, Event::ENGAGEABLE_STATUSES, true)
            || ! $this->eventAcceptsGoing($event)) {
            return;
        }

        $isGoing = app(EngagementReader::class)->isGoingToEvent(
            (string) $user->getKey(),
            (string) $event->getKey(),
        );

        if ($isGoing) {
            DB::transaction(function () use ($user, $event): void {
                $response = Response::query()
                    ->where('respondable_type', $event->getMorphClass())
                    ->where('respondable_id', $event->getKey())
                    ->where('responder_type', $user->getMorphClass())
                    ->where('responder_id', $user->getKey())
                    ->where('response_type', 'going')
                    ->active()
                    ->lockForUpdate()
                    ->first();

                $removed = app(RemoveEventGoingAction::class)->handle((string) $event->getKey(), $user);

                if ($removed['deleted'] && $response instanceof Response) {
                    app(ReverseShareOutcomeAction::class)->handle(
                        ShareOutcomeBusinessKey::eventGoing((string) $response->getKey()),
                        'going_removed',
                        $user,
                        request(),
                    );
                }
            });

            return;
        }

        DB::transaction(function () use ($user, $event): void {
            $goingState = app(MarkEventGoingAction::class)->handle($event, $user, request());

            if ($goingState['response_created']) {
                $response = Response::query()
                    ->where('respondable_type', $event->getMorphClass())
                    ->where('respondable_id', $event->getKey())
                    ->where('responder_type', $user->getMorphClass())
                    ->where('responder_id', $user->getKey())
                    ->where('response_type', 'going')
                    ->active()
                    ->lockForUpdate()
                    ->first();

                if ($response instanceof Response) {
                    app(ShareTrackingService::class)->recordOutcome(
                        type: ShareOutcomeType::EventGoing,
                        businessKey: ShareOutcomeBusinessKey::eventGoing((string) $response->getKey()),
                        subject: $event,
                        actor: $user,
                        request: request(),
                        metadata: ['subject_id' => $event->getKey(), 'subject_type' => $event->getMorphClass()],
                        occurredAt: $response->created_at,
                    );
                }
            }
        });
    }

    protected function eventAcceptsGoing(Event $event): bool
    {
        $endsAt = $event->ends_at ?? $event->starts_at;

        return ! $endsAt instanceof CarbonInterface || $endsAt->isFuture();
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function goingEventIds(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        return app(EngagementReader::class)->goingEventIds((string) $user->getKey());
    }
}
