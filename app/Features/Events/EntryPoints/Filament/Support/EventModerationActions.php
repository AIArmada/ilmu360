<?php

declare(strict_types=1);

namespace App\Features\Events\EntryPoints\Filament\Support;

use App\Features\Events\Api\EventModeration;
use App\Features\Events\EntryPoints\Filament\Pages\ModerationQueue;
use App\Features\Events\Models\Event;
use App\Features\Events\Services\EventModerationWorkflow;
use App\Features\Events\States\EventStatus\Approved;
use App\Features\Events\States\EventStatus\Cancelled;
use App\Features\Events\States\EventStatus\NeedsChanges;
use App\Features\Events\States\EventStatus\Pending;
use App\Features\Events\States\EventStatus\Rejected;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use LogicException;
use Spatie\ModelStates\Exceptions\CouldNotPerformTransition;
use Spatie\ModelStates\Exceptions\TransitionNotFound;

/**
 * Shared moderation actions for events.
 *
 * Built on the EventModeration workflow contract so the moderation queue,
 * the events table and the view page all drive the same state machine,
 * guards and failure handling.
 */
final class EventModerationActions
{
    /**
     * @param  (callable(Event): void)|null  $afterAction
     */
    public static function approveAction(?callable $afterAction = null): Action
    {
        return Action::make('approve')
            ->label('Approve')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Approve Event')
            ->modalDescription('Are you sure you want to approve this event? It will be published and made searchable.')
            ->schema([
                Textarea::make('note')
                    ->label('Note (optional)')
                    ->rows(3),
            ])
            ->authorize(fn (Event $record): bool => self::canModerate($record))
            ->visible(fn (Event $record): bool => $record->status instanceof Pending && self::canModerate($record))
            ->action(function (Event $record, array $data, EventModeration $moderation) use ($afterAction): void {
                self::perform(
                    $record,
                    function () use ($record, $data, $moderation): void {
                        $moderation->approve((string) $record->getKey(), self::actorId(), self::note($data));
                    },
                    'Event approved',
                    $afterAction,
                );
            });
    }

    /**
     * @param  (callable(Event): void)|null  $afterAction
     */
    public static function rejectAction(?callable $afterAction = null): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->modalHeading('Reject Event')
            ->modalDescription('This event will be rejected and removed from search.')
            ->schema([
                Select::make('reason_code')
                    ->label('Reason')
                    ->options(EventModerationWorkflow::reasonOptions())
                    ->required(),
                Textarea::make('note')
                    ->label('Note to Submitter')
                    ->required()
                    ->rows(3),
            ])
            ->authorize(fn (Event $record): bool => self::canModerate($record))
            ->visible(fn (Event $record): bool => $record->status instanceof Pending && self::canModerate($record))
            ->action(function (Event $record, array $data, EventModeration $moderation) use ($afterAction): void {
                self::perform(
                    $record,
                    function () use ($record, $data, $moderation): void {
                        $moderation->reject(
                            (string) $record->getKey(),
                            self::actorId() ?? '',
                            (string) $data['reason_code'],
                            (string) $data['note'],
                        );
                    },
                    'Event rejected',
                    $afterAction,
                );
            });
    }

    /**
     * @param  (callable(Event): void)|null  $afterAction
     */
    public static function requestChangesAction(?callable $afterAction = null): Action
    {
        return Action::make('needs_changes')
            ->label('Request Changes')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('warning')
            ->modalHeading('Request Changes')
            ->modalDescription('Specify what changes the submitter needs to make.')
            ->schema([
                Select::make('reason_code')
                    ->label('Reason')
                    ->options(EventModerationWorkflow::reasonOptions())
                    ->required(),
                Textarea::make('note')
                    ->label('What needs to be changed?')
                    ->required()
                    ->rows(3),
            ])
            ->authorize(fn (Event $record): bool => self::canModerate($record))
            ->visible(fn (Event $record): bool => $record->status instanceof Pending && self::canModerate($record))
            ->action(function (Event $record, array $data, EventModeration $moderation) use ($afterAction): void {
                self::perform(
                    $record,
                    function () use ($record, $data, $moderation): void {
                        $moderation->requestChanges(
                            (string) $record->getKey(),
                            self::actorId() ?? '',
                            (string) $data['reason_code'],
                            (string) $data['note'],
                        );
                    },
                    'Changes requested',
                    $afterAction,
                );
            });
    }

    /**
     * @param  (callable(Event): void)|null  $afterAction
     */
    public static function cancelAction(?callable $afterAction = null): Action
    {
        return Action::make('cancel')
            ->label('Cancel')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Cancel Event')
            ->modalDescription('This event will remain visible with a cancelled badge and notify users who saved or plan to attend.')
            ->schema([
                Textarea::make('note')
                    ->label('Cancellation note (optional)')
                    ->rows(3),
            ])
            ->authorize(fn (Event $record): bool => self::canModerate($record))
            ->visible(fn (Event $record): bool => ($record->status instanceof Pending || $record->status instanceof Approved)
                && self::canModerate($record))
            ->action(function (Event $record, array $data, EventModeration $moderation) use ($afterAction): void {
                self::perform(
                    $record,
                    function () use ($record, $data, $moderation): void {
                        $moderation->cancel((string) $record->getKey(), self::actorId(), self::note($data));
                    },
                    'Event cancelled',
                    $afterAction,
                );
            });
    }

    /**
     * @param  (callable(Event): void)|null  $afterAction
     */
    public static function reconsiderAction(?callable $afterAction = null): Action
    {
        return Action::make('reconsider')
            ->label('Reconsider')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Reconsider Event')
            ->modalDescription('Move this event back to pending for re-review.')
            ->schema([
                Textarea::make('note')
                    ->label('Reason for reconsideration')
                    ->rows(3),
            ])
            ->authorize(fn (Event $record): bool => self::canModerate($record))
            ->visible(fn (Event $record): bool => $record->status instanceof Rejected && self::canModerate($record))
            ->action(function (Event $record, array $data, EventModeration $moderation) use ($afterAction): void {
                self::perform(
                    $record,
                    function () use ($record, $data, $moderation): void {
                        $moderation->reconsider((string) $record->getKey(), self::actorId(), self::note($data));
                    },
                    'Event moved back for review',
                    $afterAction,
                );
            });
    }

    /**
     * @param  (callable(Event): void)|null  $afterAction
     */
    public static function remoderateAction(?callable $afterAction = null): Action
    {
        return Action::make('remoderate')
            ->label('Send for Re-moderation')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Send for Re-moderation')
            ->modalDescription('Move this event back to pending so moderators can review it again.')
            ->schema([
                Textarea::make('note')
                    ->label('Reason for re-moderation')
                    ->rows(3),
            ])
            ->authorize(fn (Event $record): bool => self::canModerate($record))
            ->visible(fn (Event $record): bool => ($record->status instanceof Approved || $record->status instanceof Cancelled)
                && self::canModerate($record))
            ->action(function (Event $record, array $data, EventModeration $moderation) use ($afterAction): void {
                self::perform(
                    $record,
                    function () use ($record, $data, $moderation): void {
                        $moderation->remoderate((string) $record->getKey(), self::actorId(), self::note($data));
                    },
                    'Event sent for re-moderation',
                    $afterAction,
                );
            });
    }

    /**
     * @param  (callable(Event): void)|null  $afterAction
     */
    public static function revertToDraftAction(?callable $afterAction = null): Action
    {
        return Action::make('revert_to_draft')
            ->label('Revert to Draft')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Revert to Draft')
            ->modalDescription('Move this event back to draft status.')
            ->schema([
                Textarea::make('note')
                    ->label('Reason (optional)')
                    ->rows(3),
            ])
            ->authorize(fn (Event $record): bool => self::canModerate($record))
            ->visible(fn (Event $record): bool => ($record->status instanceof Rejected
                || $record->status instanceof NeedsChanges
                || $record->status instanceof Cancelled)
                && self::canModerate($record))
            ->action(function (Event $record, array $data, EventModeration $moderation) use ($afterAction): void {
                self::perform(
                    $record,
                    function () use ($record, $data, $moderation): void {
                        $moderation->revertToDraft((string) $record->getKey(), self::actorId(), self::note($data));
                    },
                    'Event reverted to draft',
                    $afterAction,
                );
            });
    }

    /**
     * @param  (callable(Event): void)|null  $afterAction
     */
    public static function returnToPendingAction(?callable $afterAction = null): Action
    {
        return Action::make('return_to_pending')
            ->label('Return to Pending')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Return to Pending')
            ->modalDescription('Move this event back to pending for moderation review.')
            ->authorize(fn (Event $record): bool => self::canModerate($record))
            ->visible(fn (Event $record): bool => $record->status instanceof NeedsChanges && self::canModerate($record))
            ->action(function (Event $record, EventModeration $moderation) use ($afterAction): void {
                self::perform(
                    $record,
                    function () use ($record, $moderation): void {
                        $moderation->submitForModeration((string) $record->getKey());
                    },
                    'Event returned to pending review',
                    $afterAction,
                );
            });
    }

    /**
     * @param  callable(): void  $callback
     * @param  (callable(Event): void)|null  $afterAction
     */
    private static function perform(Event $record, callable $callback, string $successTitle, ?callable $afterAction): void
    {
        try {
            $callback();

            ModerationQueue::flushNavigationBadgeCache();
        } catch (CouldNotPerformTransition|TransitionNotFound) {
            self::notifyDanger(__('This event is no longer in a state that allows this action. Please refresh to see its current status.'));

            return;
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first();

            self::notifyDanger(is_string($message) && $message !== ''
                ? $message
                : __('Could not moderate this event.'));

            return;
        } catch (AuthorizationException $exception) {
            self::notifyDanger($exception->getMessage() !== ''
                ? $exception->getMessage()
                : __('You are not authorized to moderate this event.'));

            return;
        } catch (LogicException $exception) {
            self::notifyDanger($exception->getMessage() !== ''
                ? $exception->getMessage()
                : __('Could not moderate this event.'));

            return;
        } finally {
            if ($afterAction !== null) {
                $afterAction($record);
            }
        }

        Notification::make()
            ->title($successTitle)
            ->success()
            ->send();
    }

    private static function canModerate(Event $record): bool
    {
        return auth()->user()?->can('moderate', $record) ?? false;
    }

    private static function actorId(): ?string
    {
        $actorId = auth()->id();

        return $actorId === null ? null : (string) $actorId;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function note(array $data): ?string
    {
        return filled($data['note'] ?? null) ? (string) $data['note'] : null;
    }

    private static function notifyDanger(string $title): void
    {
        Notification::make()
            ->title($title !== '' ? $title : __('Could not moderate this event.'))
            ->danger()
            ->send();
    }
}
