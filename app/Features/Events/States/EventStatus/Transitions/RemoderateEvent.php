<?php

namespace App\Features\Events\States\EventStatus\Transitions;

use App\Features\Events\Models\Event;
use App\Features\Events\Notifications\EventNotificationService;
use App\Features\Events\Notifications\EventSubmittedNotification;
use App\Features\Events\Persistence\EventStatusWriter;
use App\Features\Events\States\EventStatus\Pending;
use App\Features\Moderation\Api\RecordModerationAction;
use App\Models\User;
use Filament\Support\Colors\Color;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Spatie\ModelStates\Transition;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Throwable;

class RemoderateEvent extends Transition implements HasColor, HasIcon, HasLabel
{
    public function __construct(
        public Event $event,
        public ?User $moderator = null,
        public ?string $note = null,
        public ?string $reasonCode = null,
    ) {}

    public function handle(): Event
    {
        return DB::transaction(function () {
            app(RecordModerationAction::class)->record([
                'actionable_type' => $this->event->getMorphClass(),
                'actionable_id' => $this->event->id,
                'actioned_by_type' => (new User)->getMorphClass(),
                'actioned_by_id' => $this->moderator?->id,
                'type' => 'remoderated',
                'reason' => filled($this->reasonCode) ? $this->reasonCode : 'remoderated',
                'notes' => $this->note ?? 'Approved event sent back for re-moderation.',
            ]);

            app(EventStatusWriter::class)->apply($this->event, Pending::class, [
                'last_state_change_at' => now(),
            ]);

            $event = $this->event;
            $note = $this->note;
            $moderatorId = $this->moderator?->id;

            // External effects only run once the surrounding transaction commits,
            // so a rolled-back transition never indexes, notifies, or logs. Each
            // effect is contained individually so one failure cannot mask others.
            DB::afterCommit(function () use ($event, $note, $moderatorId): void {
                try {
                    app(EventNotificationService::class)->notifySubmissionRemoderated($event, $note);
                } catch (Throwable $exception) {
                    Log::warning('Re-moderation notification failed after transition.', [
                        'event_id' => (string) $event->getKey(),
                        'exception' => $exception,
                    ]);
                }

                try {
                    $event->unsearchable();
                } catch (Throwable $exception) {
                    Log::warning('Search index removal failed after re-moderation.', [
                        'event_id' => (string) $event->getKey(),
                        'exception' => $exception,
                    ]);
                }

                try {
                    $moderators = User::role(['moderator', 'admin', 'super_admin'])->get();
                    if ($moderators->isNotEmpty()) {
                        Notification::send($moderators, new EventSubmittedNotification($event));
                    }
                } catch (RoleDoesNotExist $exception) {
                    Log::warning('Could not notify moderators: roles not found', [
                        'event_id' => $event->id,
                        'exception' => $exception,
                    ]);
                } catch (Throwable $exception) {
                    Log::warning('Moderator re-moderation notification failed.', [
                        'event_id' => (string) $event->getKey(),
                        'exception' => $exception,
                    ]);
                }

                Log::info('Approved event sent for re-moderation', [
                    'event_id' => $event->id,
                    'moderator_id' => $moderatorId,
                ]);
            });

            return $this->event;
        });
    }

    public function getLabel(): string
    {
        return __('Send for Re-moderation');
    }

    public function getColor(): string|array
    {
        return Color::Amber;
    }

    public function getIcon(): string
    {
        return 'heroicon-o-arrow-path';
    }
}
