<?php

namespace App\Filament\Resources\Events\Pages;

use App\Actions\Events\GenerateEventSlugAction;
use App\Actions\Events\SyncEventResourceRelationsAction;
use App\Enums\EventKeyPersonRole;
use App\Enums\RegistrationMode;
use App\Enums\TagType;
use App\Filament\Pages\Concerns\AuditsRelatedStateChanges;
use App\Filament\Resources\Events\Concerns\PublishesEventChanges;
use App\Filament\Resources\Events\EventResource;
use App\Models\Event;
use App\Models\EventKeyPerson;
use App\Models\Reference;
use App\Models\Series;
use App\Models\Speaker;
use App\Services\ModerationService;
use App\States\EventStatus\Approved;
use App\States\EventStatus\Draft;
use App\States\EventStatus\NeedsChanges;
use App\States\EventStatus\Pending;
use App\States\EventStatus\Rejected;
use App\Support\Events\AdminEventTimeMapper;
use App\Support\Moderation\EventModerationWorkflow;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

class EditEvent extends EditRecord
{
    use AuditsRelatedStateChanges;
    use PublishesEventChanges;

    protected static string $resource = EventResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    #[\Override]
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $event = $this->eventRecord();
        $this->captureRelatedAuditSnapshot($event);
        $event->loadMissing(['languages:id', 'tags:id,type']);

        $data['languages'] = $event->languages->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $data['domain_tags'] = $this->getTagIdsByType(TagType::Domain);
        $data['discipline_tags'] = $this->getTagIdsByType(TagType::Discipline);
        $data['source_tags'] = $this->getTagIdsByType(TagType::Source);
        $data['issue_tags'] = $this->getTagIdsByType(TagType::Issue);
        $data['registration_required'] = (bool) $event->settings?->registration_required;
        $data['registration_mode'] = $this->resolveRegistrationMode($event)->value;
        $event->loadMissing(['keyPeople']);
        $data['speakers'] = $event->keyPeople
            ->where('role', EventKeyPersonRole::Speaker)
            ->pluck('speaker_id')
            ->filter(fn (mixed $speakerId): bool => is_string($speakerId) && $speakerId !== '')
            ->values()
            ->all();
        $data['other_key_people'] = $event->keyPeople
            ->where('role', '!=', EventKeyPersonRole::Speaker)
            ->map(fn (EventKeyPerson $keyPerson): array => [
                'role' => $keyPerson->role instanceof EventKeyPersonRole ? $keyPerson->role->value : (string) $keyPerson->role,
                'speaker_id' => $keyPerson->speaker_id,
                'name' => $keyPerson->name,
                'is_public' => (bool) $keyPerson->is_public,
                'notes' => $keyPerson->notes,
            ])
            ->values()
            ->all();

        return AdminEventTimeMapper::injectFormTimeFields($data);
    }

    #[\Override]
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = AdminEventTimeMapper::normalizeForPersistence($data);

        $speakerSlugSegments = app(GenerateEventSlugAction::class)->speakerSlugSegmentsForSpeakerIds(
            is_array($data['speakers'] ?? null) ? $data['speakers'] : [],
        );

        if (
            $speakerSlugSegments === []
            && ($data['organizer_type'] ?? null) === Speaker::class
            && filled($data['organizer_id'] ?? null)
        ) {
            $speakerSlugSegments = app(GenerateEventSlugAction::class)->speakerSlugSegmentsForSpeakerIds([
                (string) $data['organizer_id'],
            ]);
        }

        $data['slug'] = app(GenerateEventSlugAction::class)->handle(
            (string) ($data['title'] ?? $this->eventRecord()->title ?? 'Event'),
            $data['event_date'] ?? $data['starts_at'] ?? $this->eventRecord()->starts_at,
            is_string($data['timezone'] ?? null)
                ? $data['timezone']
                : (is_string($this->eventRecord()->timezone) ? $this->eventRecord()->timezone : null),
            (string) $this->eventRecord()->getKey(),
            $speakerSlugSegments,
        );

        unset(
            $data['languages'],
            $data['domain_tags'],
            $data['discipline_tags'],
            $data['source_tags'],
            $data['issue_tags'],
            $data['registration_required'],
            $data['registration_mode'],
            $data['speakers'],
            $data['other_key_people'],
        );

        return $data;
    }

    protected function afterSave(): void
    {
        $event = $this->eventRecord();
        $changedFields = array_keys($event->getChanges());

        $syncResult = app(SyncEventResourceRelationsAction::class)->handle(
            $event,
            $this->form->getState(),
            lockRegistrationMode: true,
            syncKeyPeople: true,
        );

        if ($syncResult['registration_mode_locked']) {
            Notification::make()
                ->title('Registration mode is locked')
                ->body('Cannot change registration mode after registrations exist.')
                ->warning()
                ->send();
        }

        $this->notifySensitiveEditsMayNeedAnnouncement([
            ...$changedFields,
            ...$this->sensitiveRelatedChangeFields($event),
        ]);

        $this->auditRelatedStateChanges($event, 'relations_updated');
    }

    /**
     * @return array<string, list<array{id: string, title: string}>>
     */
    protected function getRelatedAuditSnapshot(Model $record): array
    {
        if (! $record instanceof Event) {
            return [];
        }

        return [
            'speakers' => $record->speakerKeyPeople()
                ->with('speaker:id,name')
                ->orderBy('order_column')
                ->get()
                ->map(fn (EventKeyPerson $keyPerson): array => [
                    'id' => (string) ($keyPerson->speaker_id ?? $keyPerson->getKey()),
                    'title' => $keyPerson->speaker instanceof Speaker ? $keyPerson->speaker->name : ($keyPerson->name ?? ''),
                ])
                ->values()
                ->all(),
            'references' => $record->references()
                ->orderBy('references.title')
                ->get(['references.id', 'references.title'])
                ->map(fn (Reference $reference): array => [
                    'id' => (string) $reference->getKey(),
                    'title' => $reference->title,
                ])
                ->values()
                ->all(),
            'series' => $record->series()
                ->orderBy('series.title')
                ->get(['series.id', 'series.title'])
                ->map(fn (Series $series): array => [
                    'id' => (string) $series->getKey(),
                    'title' => $series->title,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function previousRelatedSnapshotForSensitiveChangePrompt(): array
    {
        return $this->relatedAuditSnapshot;
    }

    /**
     * @return array<string, mixed>
     */
    protected function currentRelatedSnapshotForSensitiveChangePrompt(Event $event): array
    {
        return $this->getRelatedAuditSnapshot($event);
    }

    /**
     * @return list<string>
     */
    protected function getTagIdsByType(TagType $type): array
    {
        return $this->eventRecord()->tags
            ->where('type', $type->value)
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    protected function resolveRegistrationMode(Event $event): RegistrationMode
    {
        $rawMode = $event->settings?->registration_mode;

        if ($rawMode instanceof RegistrationMode) {
            return $rawMode;
        }

        if (is_string($rawMode)) {
            return RegistrationMode::tryFrom($rawMode) ?? RegistrationMode::Event;
        }

        return RegistrationMode::Event;
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            $this->getSubmitForModerationAction(),
            $this->getApproveAction(),
            $this->getRequestChangesAction(),
            $this->getRejectAction(),
            $this->getReconsiderAction(),
            $this->getRemoderateAction(),
            $this->getRevertToDraftAction(),
            $this->getPublishChangeAction(),
            Action::make('view')
                ->label('View')
                ->icon(Heroicon::OutlinedEye)
                ->url(fn (): string => EventResource::getUrl('view', ['record' => $this->getRecord()])),
            DeleteAction::make(),
        ];
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

                $this->redirect(EventResource::getUrl('view', ['record' => $this->eventRecord()]));
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

                $this->redirect(EventResource::getUrl('view', ['record' => $this->eventRecord()]));
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

                $this->redirect(EventResource::getUrl('view', ['record' => $this->eventRecord()]));
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

                $this->redirect(EventResource::getUrl('view', ['record' => $this->eventRecord()]));
            })
            ->visible(fn (): bool => $this->canModerate() && $this->eventRecord()->status instanceof Pending);
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

                $this->redirect(EventResource::getUrl('view', ['record' => $this->eventRecord()]));
            })
            ->visible(fn (): bool => $this->canModerate() && $this->eventRecord()->status instanceof Rejected);
    }

    protected function getRemoderateAction(): Action
    {
        return Action::make('remoderate')
            ->label('Send for Re-moderation')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Re-moderate Event')
            ->modalDescription('Send this approved event back to pending for re-review. It will be temporarily removed from search.')
            ->schema([
                Textarea::make('note')
                    ->label('Reason for re-moderation')
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->action(function (array $data, ModerationService $service): void {
                $service->remoderate($this->eventRecord(), auth()->user(), $data['note'] ?? null);

                Notification::make()
                    ->title('Event sent for re-moderation')
                    ->warning()
                    ->send();

                $this->redirect(EventResource::getUrl('view', ['record' => $this->eventRecord()]));
            })
            ->visible(fn (): bool => $this->canModerate() && $this->eventRecord()->status instanceof Approved);
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

                $this->redirect(EventResource::getUrl('view', ['record' => $this->eventRecord()]));
            })
            ->visible(fn (): bool => $this->canModerate() && (
                $this->eventRecord()->status instanceof Rejected
                || $this->eventRecord()->status instanceof NeedsChanges
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
