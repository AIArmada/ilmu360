<?php

namespace App\Filament\Ahli\Widgets;

use App\Filament\Ahli\Resources\Events\EventResource;
use App\Models\Event;
use App\Models\EventSubmission;
use App\Models\Institution;
use App\Models\Speaker;
use App\Models\User;
use App\Support\Events\SubmitterContactPresenter;
use App\Support\Timezone\UserDateTimeFormatter;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class PendingApprovalEventsWidget extends TableWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    #[\Override]
    protected function getTableHeading(): ?string
    {
        return 'Events Needing Approval';
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->query($this->getPendingApprovalEventsQuery())
            ->poll('30s')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->label('Event')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
                TextColumn::make('approval_scope')
                    ->label('Institution / Speaker')
                    ->getStateUsing(fn (Event $record): string => $this->getApprovalScopeLabel($record))
                    ->wrap(),
                TextColumn::make('submission_submitter')
                    ->label('Submitter')
                    ->getStateUsing(fn (Event $record): string => SubmitterContactPresenter::labelForEvent($record))
                    ->url(fn (Event $record): ?string => SubmitterContactPresenter::whatsappUrlForEvent($record))
                    ->openUrlInNewTab()
                    ->wrap(),
                TextColumn::make('submission_recorded_at')
                    ->label('Submitted')
                    ->getStateUsing(fn (Event $record): string => $this->getSubmissionRecordedAtLabel($record))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('created_at', $direction)),
            ])
            ->recordActions([
                Action::make('review')
                    ->label('Review')
                    ->icon('heroicon-o-pencil-square')
                    ->url(fn (Event $record): string => EventResource::getUrl('edit', ['record' => $record], panel: 'ahli'))
                    ->visible(fn (Event $record): bool => auth()->user()?->can('approve', $record) ?? false),
            ])
            ->emptyStateHeading('No events need approval right now')
            ->emptyStateDescription('Pending public submissions for your institutions and speakers will appear here.');
    }

    /**
     * @return Builder<Event>
     */
    private function getPendingApprovalEventsQuery(): Builder
    {
        $user = auth()->user();

        /** @var Builder<Event> $query */
        $query = Event::query()
            ->with([
                'organizer',
                'institution',
                'submissions.contacts',
                'submissions.submitter',
            ])
            ->where('status', 'pending')
            ->whereHas('submissions');

        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $eventQuery) use ($user): void {
            $eventQuery->orWhere(function (Builder $institutionOrganizerQuery) use ($user): void {
                $institutionOrganizerQuery
                    ->whereIn('events.organizer_type', [Institution::class, 'institution'])
                    ->whereIn(
                        'events.organizer_id',
                        $user->institutions()->select('institutions.id')
                    );
            });

            $eventQuery->orWhere(function (Builder $speakerOrganizerQuery) use ($user): void {
                $speakerOrganizerQuery
                    ->whereIn('events.organizer_type', [Speaker::class, 'speaker'])
                    ->whereIn(
                        'events.organizer_id',
                        $user->speakers()->select('speakers.id')
                    );
            });

            $eventQuery->orWhere(function (Builder $institutionLinkedQuery) use ($user): void {
                $institutionLinkedQuery
                    ->whereIn(
                        'events.institution_id',
                        $user->institutions()->select('institutions.id')
                    );
            });
        });
    }

    private function getApprovalScopeLabel(Event $record): string
    {
        if ($record->organizer instanceof Institution) {
            return 'Institution: '.$record->organizer->name;
        }

        if ($record->organizer instanceof Speaker) {
            return 'Speaker: '.$record->organizer->formatted_name;
        }

        if ($record->institution instanceof Institution) {
            return 'Institution: '.$record->institution->name;
        }

        return '-';
    }

    private function getSubmissionRecordedAtLabel(Event $record): string
    {
        $submission = $this->latestSubmission($record);

        if (! $submission instanceof EventSubmission || ! $submission->created_at) {
            return '-';
        }

        $date = UserDateTimeFormatter::translatedFormat($submission->created_at, 'd M Y');
        $time = UserDateTimeFormatter::format($submission->created_at, 'h:i A');

        return trim($date.', '.$time, ', ');
    }

    private function latestSubmission(Event $record): ?EventSubmission
    {
        /** @var EventSubmission|null $submission */
        $submission = $record->submissions
            ->sortByDesc(fn (EventSubmission $submission): int => $submission->created_at?->getTimestamp() ?? 0)
            ->first();

        return $submission;
    }
}
