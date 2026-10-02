<?php

namespace App\States\EventStatus\Transitions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Models\Event;
use App\Models\Institution;
use App\Models\ModerationReview;
use App\Models\Person;
use App\Models\Reference;
use App\Models\User;
use App\Models\Venue;
use App\Services\Notifications\EventNotificationService;
use App\States\EventStatus\Approved;
use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Spatie\ModelStates\Transition;
use Throwable;

class ApproveEvent extends Transition implements HasColor, HasIcon, HasLabel
{
    public function __construct(
        public Event $event,
        public ?User $moderator = null,
        public ?string $note = null
    ) {}

    public function handle(): Event
    {
        return DB::transaction(function () {
            if (! $this->event->occurrences()->exists()) {
                throw ValidationException::withMessages([
                    'occurrences' => __('An event must have at least one occurrence before it can be published.'),
                ]);
            }

            // Create review record
            OwnerContext::withOwner(null, fn () => ModerationReview::create([
                'actionable_type' => Event::class,
                'actionable_id' => $this->event->id,
                'actioned_by_type' => User::class,
                'actioned_by_id' => $this->moderator?->id,
                'type' => 'approve',
                'reason' => 'approved',
                'notes' => $this->note,
            ]));

            // Update event status
            $this->event->status = Approved::class;
            // @phpstan-ignore-next-line now() returns CarbonImmutable, property expects Carbon
            $this->event->published_at = now();
            $this->event->last_state_change_at = now();
            $this->event->save();
            $this->event->resolveEscalations();

            // Entity verification is a moderator authority: by approving the
            // event, a moderator implicitly verifies these entities. An
            // ordinary member's auto-approved publication must not grant
            // entity verification.
            if ($this->moderator instanceof User && $this->moderator->hasAnyRole(['moderator', 'super_admin'])) {
                $this->verifyPendingRelatedRecords($this->event);
            }

            $event = $this->event;
            $moderatorId = $this->moderator?->id;

            // External effects only run once the surrounding transaction commits,
            // so a rolled-back approval never indexes, notifies, or logs.
            // Each effect is contained individually: the DB commit already
            // succeeded, and one external failure must not mask the others.
            DB::afterCommit(function () use ($event, $moderatorId): void {
                try {
                    $event->searchable();
                } catch (Throwable $exception) {
                    Log::warning('Event search index update failed after approval.', [
                        'event_id' => (string) $event->getKey(),
                        'exception' => $exception,
                    ]);
                }

                try {
                    app(EventNotificationService::class)->notifySubmissionApproved($event);
                } catch (Throwable $exception) {
                    Log::warning('Submission-approved notification failed after approval.', [
                        'event_id' => (string) $event->getKey(),
                        'exception' => $exception,
                    ]);
                }

                try {
                    app(EventNotificationService::class)->notifyPublication($event);
                } catch (Throwable $exception) {
                    Log::warning('Publication notification failed after approval.', [
                        'event_id' => (string) $event->getKey(),
                        'exception' => $exception,
                    ]);
                }

                Log::info('Event approved', [
                    'event_id' => $event->id,
                    'moderator_id' => $moderatorId,
                ]);
            });

            return $this->event;
        });
    }

    /**
     * Auto-verify pending Person/Institution/Venue records.
     * By approving the event, the moderator implicitly verifies these related entities.
     */
    protected function verifyPendingRelatedRecords(Event $event): void
    {
        $personIds = $event->keyPeople()->where('involveable_type', 'person')->pluck('involveable_id');

        $organizer = $event->primaryOrganizerInvolvement?->involveable;
        if ($organizer instanceof Person) {
            $personIds->push((string) $organizer->getKey());
        }

        // Verify linked person profiles across all event roles.
        Person::query()
            ->whereIn('id', $personIds->unique()->values())
            ->where('status', 'pending')
            ->get()
            ->each(function (Person $person): void {
                $now = now();
                $person->forceFill([
                    'status' => 'verified',
                    'verified_at' => $now,
                    'last_state_change_at' => $now,
                ])->save();
            });

        $institutionIds = collect();

        if ($organizer instanceof Institution) {
            $institutionIds->push((string) $organizer->getKey());
        }

        if ($event->institution_id) {
            $institutionIds->push($event->institution_id);
        }

        Institution::query()
            ->whereIn('id', $institutionIds->unique()->values())
            ->where('status', 'pending')
            ->get()
            ->each(function (Institution $institution): void {
                $now = now();
                $institution->forceFill([
                    'status' => 'verified',
                    'verified_at' => $now,
                    'last_state_change_at' => $now,
                ])->save();
            });

        // Verify venue
        $venueId = $event->default_venue_id;

        if ($venueId) {
            Venue::query()
                ->whereKey($venueId)
                ->where('status', 'pending')
                ->get()
                ->each(function (Venue $venue): void {
                    $venue->forceFill(['status' => 'verified'])->save();
                });
        }

        // Verify references (taxonomy is package EventTerm/Classification — no Spatie tag dual path)
        $event->references()
            ->where('status', 'pending')
            ->get()
            ->each(function (Reference $reference): void {
                $reference->forceFill(['status' => 'verified'])->save();
            });

        Log::info('Auto-verified pending related records', [
            'event_id' => $event->id,
        ]);
    }

    public function getLabel(): string
    {
        return __('Approve Event');
    }

    public function getColor(): string|array
    {
        return Color::Emerald;
    }

    public function getIcon(): string
    {
        return 'heroicon-o-check-circle';
    }
}
