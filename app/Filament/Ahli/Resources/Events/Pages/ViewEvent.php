<?php

namespace App\Filament\Ahli\Resources\Events\Pages;

use App\Filament\Ahli\Resources\Events\EventResource;
use App\Filament\Resources\Events\Concerns\PublishesEventChanges;
use App\Models\Event;
use App\Models\Institution;
use App\Models\User;
use App\Support\Submission\EntitySubmissionAccess;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
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
            Action::make('add_child_event')
                ->label('Add Child Event')
                ->icon(Heroicon::OutlinedPlus)
                ->url(fn (): string => route('submit-event.create', ['parent' => $this->eventRecord()->getKey()]))
                ->visible(fn (): bool => $this->eventRecord()->isParentProgram()),
            Action::make('duplicate_event')
                ->label('Duplicate Event')
                ->url(fn (): string => $this->duplicateEventUrl())
                ->visible(fn (): bool => auth()->user()?->can('update', $this->eventRecord()) ?? false),
            $this->getPublishChangeAction(),
            EditAction::make(),
            Action::make('view_public')
                ->label('View Public Page')
                ->icon(Heroicon::OutlinedEye)
                ->url(fn (): string => route('events.show', $this->eventRecord()))
                ->openUrlInNewTab(),
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

    protected function eventRecord(): Event
    {
        $record = $this->getRecord();

        if (! $record instanceof Event) {
            throw new \RuntimeException('Expected Filament record to be an Event instance.');
        }

        return $record;
    }
}
