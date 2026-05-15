<?php

namespace App\Filament\Resources\Events\Pages;

use App\Enums\EventChangeType;
use App\Filament\Resources\Events\Concerns\PublishesEventChanges;
use App\Filament\Resources\Events\EventResource;
use App\Models\Event;
use App\Models\Institution;
use App\Models\User;
use App\Services\ModerationService;
use App\States\EventStatus\Approved;
use App\States\EventStatus\Cancelled;
use App\States\EventStatus\Draft;
use App\States\EventStatus\NeedsChanges;
use App\States\EventStatus\Pending;
use App\States\EventStatus\Rejected;
use App\Support\Moderation\EventModerationWorkflow;
use App\Support\Submission\EntitySubmissionAccess;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

class ViewEvent extends ViewRecord
{
    use PublishesEventChanges;

    protected static string $resource = EventResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            $this->getSubmitForModerationAction(),
            $this->getApproveAction(),
            $this->getRequestChangesAction(),
            $this->getCancelAction(),
            $this->getRejectAction(),
            $this->getReconsiderAction(),
            $this->getRemoderateAction(),
            $this->getRevertToDraftAction(),
            $this->getPublishChangeAction(),
            Action::make('duplicate_event')
                ->label('Duplicate Event')
                ->url(fn (): string => $this->duplicateEventUrl()),
            EditAction::make(),
        ];
    }

    protected function duplicateEventUrl(): string
    {
        $event = $this->eventRecord();
        $institutionId = $this->duplicateScopedInstitutionId($event, auth()->user());

        if ($institutionId !== null) {
            return route('dashboard.institutions.submit-event', [
                'institution' => $institutionId,
                'duplicate' => $event->getKey(),
            ]);
        }

        return route('submit-event.create', ['duplicate' => $event->getKey()]);
    }

    protected function duplicateScopedInstitutionId(Event $event, mixed $user): ?string
    {
        if (! $user instanceof User) {
            return null;
        }

        $organizerInstitutionId = $event->organizer_type === Institution::class && is_string($event->organizer_id)
            ? $event->organizer_id
            : null;

        if ($organizerInstitutionId !== null) {
            return $this->userIsInstitutionMember($user, $organizerInstitutionId)
                ? $organizerInstitutionId
                : null;
        }

        $linkedInstitutionId = is_string($event->institution_id) ? $event->institution_id : null;

        if ($linkedInstitutionId === null) {
            return null;
        }

        return $this->userIsInstitutionMember($user, $linkedInstitutionId)
            ? $linkedInstitutionId
            : null;
    }

    protected function userIsInstitutionMember(User $user, string $institutionId): bool
    {
        return app(EntitySubmissionAccess::class)->canUseMemberInstitution($user, $institutionId);
    }

    protected function getApproveAction(): Action
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
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->action(function (array $data, ModerationService $service): void {
                $service->approve($this->eventRecord(), auth()->user(), $data['note'] ?? null);

                Notification::make()
                    ->title('Event approved')
                    ->success()
                    ->send();

                $this->refreshFormData(['status', 'published_at']);
            })
            ->visible(fn (): bool => $this->canModerate() && $this->eventRecord()->status instanceof Pending);
    }

    protected function getSubmitForModerationAction(): Action
    {
        return Action::make('submit_for_moderation')
            ->label('Submit for Moderation')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Submit Event for Moderation')
            ->modalDescription('Move this draft event to pending so moderators can review it.')
            ->action(function (ModerationService $service): void {
                $service->submitForModeration($this->eventRecord());

                Notification::make()
                    ->title('Event submitted for moderation')
                    ->success()
                    ->send();

                $this->refreshFormData(['status']);
            })
            ->visible(fn (): bool => $this->canModerate() && $this->eventRecord()->status instanceof Draft);
    }

    protected function getRequestChangesAction(): Action
    {
        return Action::make('request_changes')
            ->label('Request Changes')
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->color('warning')
            ->modalHeading('Request Changes')
            ->modalDescription('Specify what changes the submitter needs to make.')
            ->schema([
                Select::make('reason_code')
                    ->label('Reason')
                    ->options(self::getReasonCodeOptions())
                    ->required(),
                Textarea::make('note')
                    ->label('Details for Submitter')
                    ->required()
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->action(function (array $data, ModerationService $service): void {
                $service->requestChanges(
                    $this->eventRecord(),
                    auth()->user(),
                    $data['reason_code'],
                    $data['note']
                );

                Notification::make()
                    ->title('Changes requested')
                    ->warning()
                    ->send();

                $this->refreshFormData(['status']);
            })
            ->visible(fn (): bool => $this->canModerate() && $this->eventRecord()->status instanceof Pending);
    }

    protected function getRejectAction(): Action
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
                    ->options(self::getReasonCodeOptions())
                    ->required(),
                Textarea::make('note')
                    ->label('Note to Submitter')
                    ->required()
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->action(function (array $data, ModerationService $service): void {
                $service->reject(
                    $this->eventRecord(),
                    auth()->user(),
                    $data['reason_code'],
                    $data['note']
                );

                Notification::make()
                    ->title('Event rejected')
                    ->danger()
                    ->send();

                $this->refreshFormData(['status']);
            })
            ->visible(fn (): bool => $this->canModerate() && $this->eventRecord()->status instanceof Pending);
    }

    protected function getCancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Cancel Event')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->modalHeading('Cancel Event')
            ->modalDescription('This event will remain visible with a cancelled notice and notify committed users.')
            ->schema([
                Textarea::make('note')
                    ->label('Public cancellation message')
                    ->rows(3)
                    ->maxLength(2000)
                    ->required(),
            ])
            ->action(function (array $data): void {
                $this->publishChangeAnnouncement([
                    'type' => EventChangeType::Cancelled->value,
                    'public_message' => $data['note'] ?? null,
                    'internal_note' => $data['note'] ?? null,
                    'notify' => true,
                ]);
            })
            ->visible(fn (): bool => $this->canModerate() && (
                $this->eventRecord()->status instanceof Pending
                || $this->eventRecord()->status instanceof Approved
            ));
    }

    protected function getReconsiderAction(): Action
    {
        return Action::make('reconsider')
            ->label('Reconsider')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Reconsider Rejected Event')
            ->modalDescription('Move this rejected event back to pending for re-review.')
            ->schema([
                Textarea::make('note')
                    ->label('Reason for reconsideration')
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->action(function (array $data, ModerationService $service): void {
                $service->reconsider($this->eventRecord(), auth()->user(), $data['note'] ?? null);

                Notification::make()
                    ->title('Event moved back to pending review')
                    ->success()
                    ->send();

                $this->refreshFormData(['status']);
            })
            ->visible(fn (): bool => $this->canModerate() && $this->eventRecord()->status instanceof Rejected);
    }

    protected function getRemoderateAction(): Action
    {
        $isCancelled = $this->eventRecord()->status instanceof Cancelled;

        return Action::make('remoderate')
            ->label($isCancelled ? 'Reopen for Review' : 'Send for Re-moderation')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading($isCancelled ? 'Reopen Cancelled Event' : 'Re-moderate Event')
            ->modalDescription(
                $isCancelled
                    ? 'Move this cancelled event back to pending for moderation review.'
                    : 'Send this approved event back to pending for re-review. It will be temporarily removed from search.'
            )
            ->schema([
                Textarea::make('note')
                    ->label($isCancelled ? 'Reason for reopening' : 'Reason for re-moderation')
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->action(function (array $data, ModerationService $service): void {
                $isCancelledNow = $this->eventRecord()->status instanceof Cancelled;
                $service->remoderate($this->eventRecord(), auth()->user(), $data['note'] ?? null);

                Notification::make()
                    ->title($isCancelledNow ? 'Cancelled event reopened for review' : 'Event sent for re-moderation')
                    ->warning()
                    ->send();

                $this->refreshFormData(['status', 'published_at']);
            })
            ->visible(fn (): bool => $this->canModerate() && (
                $this->eventRecord()->status instanceof Approved
                || $this->eventRecord()->status instanceof Cancelled
            ));
    }

    protected function getRevertToDraftAction(): Action
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
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->action(function (array $data, ModerationService $service): void {
                $service->revertToDraft($this->eventRecord(), auth()->user(), $data['note'] ?? null);

                Notification::make()
                    ->title('Event reverted to draft')
                    ->send();

                $this->refreshFormData(['status', 'published_at']);
            })
            ->visible(fn (): bool => $this->canModerate() && (
                $this->eventRecord()->status instanceof Rejected
                || $this->eventRecord()->status instanceof NeedsChanges
                || $this->eventRecord()->status instanceof Cancelled
            ));
    }

    protected function canModerate(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'moderator']) ?? false;
    }

    /**
     * @return array<string, string>
     */
    protected static function getReasonCodeOptions(): array
    {
        return EventModerationWorkflow::reasonOptions();
    }

    protected function eventRecord(): Event
    {
        $record = $this->getRecord();

        if (! $record instanceof Event) {
            throw new \RuntimeException('Expected Filament record to be an Event instance.');
        }

        return $record;
    }
}
