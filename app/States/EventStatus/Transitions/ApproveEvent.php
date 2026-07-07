<?php

namespace App\States\EventStatus\Transitions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Models\Event;
use App\Models\Institution;
use App\Models\ModerationReview;
use App\Models\Reference;
use App\Models\Speaker;
use App\Models\Tag;
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
use Spatie\ModelStates\Transition;

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
            $this->event->save();

            // Auto-verify pending related records (by approving the event, moderator implicitly verifies these entities)
            $this->verifyPendingRelatedRecords($this->event);

            // Make searchable (Scout)
            $this->event->searchable();

            app(EventNotificationService::class)->notifySubmissionApproved($this->event);
            app(EventNotificationService::class)->notifyPublication($this->event);

            Log::info('Event approved', [
                'event_id' => $this->event->id,
                'moderator_id' => $this->moderator?->id,
            ]);

            return $this->event;
        });
    }

    /**
     * Auto-verify pending Speaker/Institution/Venue records.
     * By approving the event, the moderator implicitly verifies these related entities.
     */
    protected function verifyPendingRelatedRecords(Event $event): void
    {
        $speakerIds = $event->keyPeople()->where('involveable_type', 'speaker')->pluck('involveable_id');

        $organizer = $event->primaryOrganizerInvolvement?->involveable;
        if ($organizer instanceof Speaker) {
            $speakerIds->push((string) $organizer->getKey());
        }

        // Verify linked speaker profiles across all event roles.
        Speaker::query()
            ->whereIn('id', $speakerIds->unique()->values())
            ->where('status', 'pending')
            ->get()
            ->each(function (Speaker $speaker): void {
                $speaker->forceFill(['status' => 'verified'])->save();
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
                $institution->forceFill(['status' => 'verified'])->save();
            });

        // Verify venue
        if ($event->venue_id) {
            Venue::query()
                ->whereKey($event->venue_id)
                ->where('status', 'pending')
                ->get()
                ->each(function (Venue $venue): void {
                    $venue->forceFill(['status' => 'verified'])->save();
                });
        }

        // Verify tags
        Tag::query()
            ->whereIn('id', $event->tags->pluck('id')->unique()->values())
            ->where('status', 'pending')
            ->get()
            ->each(function (Tag $tag): void {
                $tag->forceFill(['status' => 'verified'])->save();
            });

        // Verify references
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
