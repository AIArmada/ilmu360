<?php

namespace App\Filament\Ahli\Widgets;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\FilamentEvents\Resources\EventResource;
use App\Models\Event;
use App\Models\EventSubmission;
use App\Models\Institution;
use App\Models\Person;
use App\Shared\Auth\AccountActor;
use App\Shared\Membership\MembershipDirectory;
use App\Shared\Membership\MembershipSubjectType;
use App\Support\Events\SubmitterContactPresenter;
use App\Support\Timezone\UserDateTimeFormatter;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class PendingApprovalEventsWidget extends TableWidget
{
    protected static bool $isLazy = false;

    public function boot(): void
    {
        OwnerContext::setForRequest(null);
    }

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
                    ->label('Institution / Person')
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
            ->emptyStateDescription('Pending public submissions for your institutions and persons will appear here.');
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
                'primaryOrganizerInvolvement.involveable',
                'institution',
                'submissions.submitter',
            ])
            ->where('status', 'pending')
            ->whereHas('submissions');

        if (! $user instanceof AccountActor) {
            return $query->whereRaw('1 = 0');
        }

        $memberships = app(MembershipDirectory::class);
        $institutionIds = $memberships->subjectIdsFor($user->actorId(), MembershipSubjectType::Institution);
        $personIds = $memberships->subjectIdsFor($user->actorId(), MembershipSubjectType::Person);

        return $query->where(function (Builder $eventQuery) use ($institutionIds, $personIds): void {
            $eventQuery->orWhere(function (Builder $involvementOrganizerQuery) use ($institutionIds, $personIds): void {
                $involvementOrganizerQuery->whereHas('involvements', function (Builder $involvementQuery) use ($institutionIds, $personIds): void {
                    $involvementQuery
                        ->where('role_code', 'organizer')
                        ->where('is_primary', true)
                        ->where(function (Builder $q) use ($institutionIds, $personIds): void {
                            $q->where(function (Builder $instQuery) use ($institutionIds): void {
                                $instQuery->whereIn('involveable_type', [Institution::class, 'institution'])
                                    ->whereIn('involveable_id', $institutionIds);
                            })->orWhere(function (Builder $spkQuery) use ($personIds): void {
                                $spkQuery->whereIn('involveable_type', [Person::class, 'person'])
                                    ->whereIn('involveable_id', $personIds);
                            });
                        });
                });
            });

            $eventQuery->orWhere(function (Builder $institutionLinkedQuery) use ($institutionIds): void {
                // Event location institution is stored on the events table.
                $institutionLinkedQuery->whereIn('institution_id', $institutionIds);
            });
        });
    }

    private function getApprovalScopeLabel(Event $record): string
    {
        if ($record->organizer instanceof Institution) {
            return 'Institution: '.$record->organizer->name;
        }

        if ($record->organizer instanceof Person) {
            return 'Person: '.$record->organizer->formatted_name;
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

        $createdAt = Carbon::instance($submission->created_at);
        $date = UserDateTimeFormatter::translatedFormat($createdAt, 'd M Y');
        $time = UserDateTimeFormatter::format($createdAt, 'h:i A');

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
