<?php

namespace App\Features\Events\EntryPoints\Filament\Pages;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Data\EventEscalationType;
use App\Features\Events\Api\OccurrenceSubmissionReviews;
use App\Features\Events\EntryPoints\Filament\Resources\Events\EventResource;
use App\Features\Events\EntryPoints\Filament\Support\EventModerationActions;
use App\Features\Events\Models\Event;
use App\Features\Events\Models\EventSubmission;
use App\Features\Events\Persistence\ModerationQueueBadgeCacheStore;
use App\Features\Events\States\EventStatus\Approved;
use App\Features\Events\States\EventStatus\NeedsChanges;
use App\Features\Events\States\EventStatus\Pending;
use App\Features\Events\States\EventStatus\Rejected;
use App\Features\Institutions\Api\Enums\InstitutionStatus;
use App\Features\Reports\Api\ReportReader;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Spatie\ModelStates\Exceptions\CouldNotPerformTransition;
use Spatie\ModelStates\Exceptions\TransitionNotFound;
use UnitEnum;

class ModerationQueue extends Page implements HasTable
{
    use InteractsWithTable;

    public function boot(): void
    {
        OwnerContext::setForRequest(null);
    }

    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    #[\Override]
    protected static ?string $navigationLabel = 'Moderation Queue';

    #[\Override]
    protected static string|UnitEnum|null $navigationGroup = 'Moderation';

    #[\Override]
    protected static ?int $navigationSort = 1;

    #[\Override]
    protected string $view = 'filament.pages.moderation-queue';

    /**
     * @var list<string>
     */
    protected const AVAILABLE_TABS = [
        'pending',
        'pending_occurrences',
        'needs_changes',
        'reports',
        'recently_rejected',
    ];

    #[Url(as: 'tab')]
    public string $activeTab = 'pending';

    /**
     * Report entity ids are the same for every tab badge and the table query
     * within one request; resolved once per request and cleared on tab change.
     *
     * @var list<string>|null
     */
    private ?array $cachedOpenReportEntityIds = null;

    public function mount(): void
    {
        $this->activeTab = $this->normalizeActiveTab($this->activeTab);
    }

    public function setActiveTab(string $tab): void
    {
        $this->unmountTableAction();
        $this->activeTab = $this->normalizeActiveTab($tab);
        $this->cachedOpenReportEntityIds = null;

        $this->resetTable();
        $this->resetTableSearch();
        $this->resetTableColumnSearches();
        $this->tableSort = null;
    }

    public function getTabBadgeColorClasses(string $badgeColor): string
    {
        return match ($badgeColor) {
            'warning' => 'bg-warning-100 text-warning-700 dark:bg-warning-500/20 dark:text-warning-400',
            'info' => 'bg-info-100 text-info-700 dark:bg-info-500/20 dark:text-info-400',
            'danger' => 'bg-danger-100 text-danger-700 dark:bg-danger-500/20 dark:text-danger-400',
            default => 'bg-gray-100 text-gray-700 dark:bg-gray-500/20 dark:text-gray-400',
        };
    }

    /**
     * @return array<string, array{label: string, icon: string, count: int, badgeColor: string}>
     */
    public function getTabs(): array
    {
        $counts = Event::query()
            ->toBase()
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as pending', [Pending::$name])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as needs_changes', [NeedsChanges::$name])
            ->selectRaw('sum(case when status = ? and updated_at >= ? then 1 else 0 end) as recently_rejected', [Rejected::$name, now()->subDays(7)])
            ->first();

        /** @var array<string, int|string|null> $countsArray */
        $countsArray = is_object($counts) ? (array) $counts : [];

        $count = static fn (string $key): int => (int) ($countsArray[$key] ?? 0);

        return [
            'pending' => [
                'label' => 'Pending',
                'icon' => 'heroicon-o-clock',
                'count' => $count('pending'),
                'badgeColor' => 'warning',
            ],
            'pending_occurrences' => [
                'label' => 'Pending Occurrences',
                'icon' => 'heroicon-o-calendar-days',
                'count' => $this->pendingOccurrenceSubmissionsQuery()->count(),
                'badgeColor' => 'warning',
            ],
            'needs_changes' => [
                'label' => 'Needs Changes',
                'icon' => 'heroicon-o-pencil-square',
                'count' => $count('needs_changes'),
                'badgeColor' => 'info',
            ],
            'reports' => [
                'label' => 'Reports',
                'icon' => 'heroicon-o-flag',
                'count' => count($this->openReportEntityIds()),
                'badgeColor' => 'danger',
            ],
            'recently_rejected' => [
                'label' => 'Recently Rejected',
                'icon' => 'heroicon-o-x-circle',
                'count' => $count('recently_rejected'),
                'badgeColor' => 'gray',
            ],
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $count = app(ModerationQueueBadgeCacheStore::class)->rememberPendingCount(static fn (): int => Event::query()
            ->withoutOwnerScope()
            ->where('status', Pending::$name)
            ->count()
            + self::pendingOccurrenceSubmissionsBaseQuery()->count());

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function flushNavigationBadgeCache(): void
    {
        app(ModerationQueueBadgeCacheStore::class)->forgetPendingCount();
    }

    public function table(Table $table): Table
    {
        if ($this->activeTab === 'pending_occurrences') {
            return $this->occurrenceSubmissionsTable($table);
        }

        return $table
            ->query(fn (): Builder => $this->getTableQuery())
            ->defaultSort(fn (Builder $query, string $direction): Builder => $query
                ->orderByRaw(
                    'case when exists (select 1 from event_escalations where event_escalations.event_id = events.id and event_escalations.type = ? and event_escalations.resolved_at is null) then 1 else 0 end desc',
                    [EventEscalationType::Priority->value],
                )
                ->orderBy('events.starts_at', $direction)
                ->orderByDesc('events.created_at'))
            ->columns([
                IconColumn::make('priority')
                    ->label('Priority')
                    ->boolean()
                    ->state(fn (Event $record): bool => $record->escalations->contains(
                        fn ($escalation): bool => $escalation->getAttribute('type') === EventEscalationType::Priority
                            && $escalation->getAttribute('resolved_at') === null,
                    ))
                    ->trueIcon('heroicon-o-exclamation-triangle')
                    ->falseIcon('heroicon-o-minus')
                    ->trueColor('danger')
                    ->falseColor('gray'),
                TextColumn::make('title')
                    ->searchable()
                    ->limit(40)
                    ->tooltip(fn ($record) => $record->title),
                TextColumn::make('institution.name')
                    ->label('Institution')
                    ->limit(20)
                    ->tooltip(fn (Event $record): ?string => $record->institution?->name),
                TextColumn::make('venue.name')
                    ->label('Venue')
                    ->limit(20)
                    ->placeholder('None')
                    ->tooltip(fn (Event $record): ?string => $record->venue?->name),
                TextColumn::make('institution.status')
                    ->label('Institution Status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof InstitutionStatus ? $state->getLabel() : 'None')
                    ->color(fn ($state): string => $state instanceof InstitutionStatus ? $state->color() : 'gray'),
                TextColumn::make('venue.status')
                    ->label('Venue Status')
                    ->badge()
                    ->state(function (Event $record): string {
                        $venue = $record->venue;

                        if ($venue === null) {
                            return 'none';
                        }

                        return (string) ($venue->getAttribute('status') ?? 'none');
                    })
                    ->formatStateUsing(fn ($state): string => $state === 'none' ? 'None' : Str::title(str_replace('_', ' ', (string) $state)))
                    ->color(fn ($state): string => match ((string) $state) {
                        'verified' => 'success',
                        'pending' => 'warning',
                        'rejected' => 'danger',
                        'inactive' => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('persons_status')
                    ->label('Persons Status')
                    ->badge()
                    ->state(function (Event $record): string {
                        $total = $record->persons->count();

                        if ($total === 0) {
                            return 'None';
                        }

                        $needsReview = $record->persons
                            ->filter(fn ($person): bool => (string) $person->getAttribute('status') !== 'verified')
                            ->count();

                        return $needsReview === 0 ? 'All verified' : $needsReview.' needs review';
                    })
                    ->tooltip(function (Event $record): ?string {
                        $pendingPersons = $record->persons
                            ->filter(fn ($person): bool => (string) $person->getAttribute('status') !== 'verified')
                            ->map(fn ($person): string => (string) ($person->getAttribute('formatted_name') ?? $person->getAttribute('name') ?? ''))
                            ->filter(fn (string $name): bool => filled($name))
                            ->implode(', ');

                        return filled($pendingPersons) ? $pendingPersons : null;
                    })
                    ->color(fn ($state): string => match (true) {
                        $state === 'All verified' => 'success',
                        $state === 'None' => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('references_status')
                    ->label('References Status')
                    ->badge()
                    ->state(function (Event $record): string {
                        $total = $record->references->count();

                        if ($total === 0) {
                            return 'None';
                        }

                        $needsReview = $record->references->where('status', '!=', 'verified')->count();

                        return $needsReview === 0 ? 'All verified' : $needsReview.' needs review';
                    })
                    ->tooltip(function (Event $record): ?string {
                        $pendingReferences = $record->references
                            ->filter(fn ($reference): bool => (string) $reference->getAttribute('status') !== 'verified')
                            ->pluck('title')
                            ->filter(fn (mixed $title): bool => filled($title))
                            ->implode(', ');

                        return filled($pendingReferences) ? $pendingReferences : null;
                    })
                    ->color(fn ($state): string => match (true) {
                        $state === 'All verified' => 'success',
                        $state === 'None' => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('open_reports_count')
                    ->label('Open Reports')
                    ->badge()
                    ->state(fn (Event $record): int => (int) $record->getAttribute('open_reports_count'))
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
                TextColumn::make('starts_at')
                    ->label('Event Date')
                    ->dateTime('M d, Y H:i')
                    ->sortable(),
                TextColumn::make('latestModerationReview.reason')
                    ->label('Latest Reason')
                    ->formatStateUsing(fn (?string $state): string => filled($state) ? Str::title(str_replace('_', ' ', $state)) : '-')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('latestModerationReview.notes')
                    ->label('Latest Moderation Note')
                    ->limit(80)
                    ->tooltip(fn (Event $record): ?string => $record->latestModerationReview?->notes)
                    ->placeholder('-')
                    ->wrap(),
                TextColumn::make('created_at')
                    ->label('Submitted')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('institution_id')
                    ->label('Institution')
                    ->relationship('institution', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                EventModerationActions::approveAction(),
                EventModerationActions::rejectAction(),
                EventModerationActions::requestChangesAction(),
                EventModerationActions::cancelAction(),
                EventModerationActions::reconsiderAction(),
                EventModerationActions::revertToDraftAction(),
                EventModerationActions::remoderateAction(),
                EventModerationActions::returnToPendingAction(),
                Action::make('view')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Event $record) => EventResource::getUrl('view', ['record' => $record])),
                Action::make('edit')
                    ->label('Edit')
                    ->icon('heroicon-o-pencil-square')
                    ->url(fn (Event $record) => EventResource::getUrl('edit', ['record' => $record])),
            ])
            ->recordUrl(fn (Event $record): string => EventResource::getUrl('view', ['record' => $record]))
            ->poll('30s');
    }

    private function occurrenceSubmissionsTable(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->pendingOccurrenceSubmissionsQuery()
                ->with(['event', 'occurrence', 'submitter']))
            ->defaultSort('submitted_at', 'desc')
            ->columns([
                TextColumn::make('submission_kind')
                    ->label(__('Type'))
                    ->badge()
                    ->state(fn (EventSubmission $record): string => data_get($record->submission_data, 'schedule_change') === null
                        ? 'new_occurrence'
                        : 'schedule_change')
                    ->formatStateUsing(fn (string $state): string => $state === 'schedule_change'
                        ? __('Schedule Change')
                        : __('New Occurrence'))
                    ->color(fn (string $state): string => $state === 'schedule_change' ? 'warning' : 'info'),
                TextColumn::make('occurrence.title')
                    ->label('Occurrence')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('event.title')
                    ->label('Event Group')
                    ->searchable()
                    ->limit(40)
                    ->tooltip(function (EventSubmission $record): ?string {
                        $event = $record->event;
                        $title = $event instanceof Model ? $event->getAttribute('title') : null;

                        return is_string($title) && $title !== '' ? $title : null;
                    }),
                TextColumn::make('occurrence.starts_at')
                    ->label('Event Date')
                    ->dateTime('M d, Y H:i')
                    ->sortable(),
                TextColumn::make('submitter')
                    ->label(__('Submitter'))
                    ->state(fn (EventSubmission $record): string => $this->submitterLabel($record))
                    ->description(function (EventSubmission $record): ?string {
                        $submitter = $record->submitter;
                        $email = $submitter instanceof Model ? $submitter->getAttribute('email') : null;

                        return is_string($email) && $email !== '' ? $email : null;
                    }),
                TextColumn::make('submission_data.schedule_change.values')
                    ->label(__('Proposed schedule'))
                    ->state(function (EventSubmission $record): array {
                        $values = data_get($record->submission_data, 'schedule_change.values');

                        if (! is_array($values)) {
                            return [];
                        }

                        $original = data_get($record->submission_data, 'schedule_change.original');
                        $original = is_array($original) ? $original : [];

                        $labels = [
                            'title' => __('Title'),
                            'starts_at' => __('Starts (UTC)'),
                            'ends_at' => __('Ends (UTC)'),
                            'timezone' => __('Timezone'),
                            'capacity' => __('Capacity'),
                            'summary' => __('Short summary'),
                            'description' => __('Description'),
                        ];

                        return collect($labels)
                            ->filter(fn (string $label, string $key): bool => array_key_exists($key, $values))
                            ->map(function (string $label, string $key) use ($values, $original): string {
                                $proposed = $this->submissionValueText($values[$key] ?? null);
                                $before = $original[$key] ?? null;

                                if ($before !== null && $this->submissionValueText($before) !== $proposed) {
                                    return $label.' — '.$this->submissionValueText($before).' → '.$proposed;
                                }

                                return $label.' — '.$proposed;
                            })
                            ->values()->all();
                    })
                    ->listWithLineBreaks()
                    ->wrap(),
                TextColumn::make('submission_data.notes')
                    ->label(__('Contributor note'))
                    ->wrap(),
                TextColumn::make('submitted_at')
                    ->label('Submitted')
                    ->since()
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('approve_occurrence')
                    ->authorize(fn (EventSubmission $record): bool => $this->canReviewOccurrence($record))
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Approve Occurrence')
                    ->schema([
                        Textarea::make('note')
                            ->label('Note (optional)')
                            ->rows(3),
                    ])
                    ->action(function (EventSubmission $record, array $data, OccurrenceSubmissionReviews $reviews): void {
                        $moderator = auth()->user();

                        abort_unless($moderator instanceof User, 403);

                        try {
                            $reviews->approve(
                                (string) $record->getKey(),
                                $moderator->getMorphClass(),
                                (string) $moderator->getKey(),
                                filled($data['note'] ?? null) ? (string) $data['note'] : null,
                            );
                        } catch (ValidationException $exception) {
                            $this->notifyDanger($this->validationMessage($exception, __('Could not approve this occurrence.')));

                            return;
                        } catch (AuthorizationException $exception) {
                            $this->notifyDanger($exception->getMessage() !== ''
                                ? $exception->getMessage()
                                : __('You are not authorized to review this occurrence submission.'));

                            return;
                        } catch (CouldNotPerformTransition|TransitionNotFound) {
                            $this->notifyDanger(__('This submission is no longer pending review. Please refresh to see its current status.'));

                            return;
                        }

                        self::flushNavigationBadgeCache();

                        Notification::make()
                            ->title(__('Occurrence approved'))
                            ->success()
                            ->send();
                    }),
                Action::make('reject_occurrence')
                    ->authorize(fn (EventSubmission $record): bool => $this->canReviewOccurrence($record))
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Reject Occurrence')
                    ->schema([
                        Select::make('reason_code')
                            ->label('Reason')
                            ->options([
                                'incomplete_info' => 'Incomplete Information',
                                'duplicate' => 'Duplicate Event',
                                'inappropriate' => 'Inappropriate Content',
                                'spam' => 'Spam',
                                'wrong_category' => 'Wrong Category',
                                'inaccurate_details' => 'Inaccurate Details',
                                'other' => 'Other',
                            ])
                            ->required(),
                        Textarea::make('note')
                            ->label('Note to Submitter')
                            ->required()
                            ->rows(3),
                    ])
                    ->action(function (EventSubmission $record, array $data, OccurrenceSubmissionReviews $reviews): void {
                        $moderator = auth()->user();

                        abort_unless($moderator instanceof User, 403);

                        try {
                            $reviews->reject(
                                (string) $record->getKey(),
                                $moderator->getMorphClass(),
                                (string) $moderator->getKey(),
                                (string) $data['reason_code'],
                                (string) $data['note'],
                            );
                        } catch (ValidationException $exception) {
                            $this->notifyDanger($this->validationMessage($exception, __('Could not reject this occurrence.')));

                            return;
                        } catch (AuthorizationException $exception) {
                            $this->notifyDanger($exception->getMessage() !== ''
                                ? $exception->getMessage()
                                : __('You are not authorized to review this occurrence submission.'));

                            return;
                        } catch (CouldNotPerformTransition|TransitionNotFound) {
                            $this->notifyDanger(__('This submission is no longer pending review. Please refresh to see its current status.'));

                            return;
                        }

                        self::flushNavigationBadgeCache();

                        Notification::make()
                            ->title(__('Occurrence rejected'))
                            ->danger()
                            ->send();
                    }),
                Action::make('view_event')
                    ->label('View Event')
                    ->icon('heroicon-o-eye')
                    ->url(fn (EventSubmission $record): string => EventResource::getUrl('view', ['record' => $record->event_id])),
            ])
            ->recordUrl(fn (EventSubmission $record): string => EventResource::getUrl('view', ['record' => $record->event_id]))
            ->poll('30s');
    }

    /**
     * @return Builder<EventSubmission>
     */
    public static function pendingOccurrenceSubmissionsBaseQuery(): Builder
    {
        return EventSubmission::query()
            ->where('status', 'pending')
            ->whereNotNull('event_occurrence_id')
            ->whereNull('event_session_id')
            ->whereHas('event', fn (Builder $query) => $query->whereState('status', Approved::class))
            ->where(fn (Builder $query) => $query
                ->whereHas('occurrence', fn (Builder $occurrences) => $occurrences->where('status', 'draft'))
                ->orWhereNotNull('submission_data->schedule_change'));
    }

    /**
     * @return Builder<EventSubmission>
     */
    private function pendingOccurrenceSubmissionsQuery(): Builder
    {
        return self::pendingOccurrenceSubmissionsBaseQuery();
    }

    /**
     * @return Builder<Event>
     */
    protected function getTableQuery(): Builder
    {
        $query = Event::query()
            ->with(['institution', 'venue', 'persons', 'references', 'latestModerationReview', 'escalations'])
            ->withCount([
                'reports as open_reports_count' => fn (Builder $reportQuery) => $reportQuery->where('status', 'open'),
            ]);

        return match ($this->activeTab) {
            'needs_changes' => $query->whereState('status', NeedsChanges::class),
            'reports' => $query->whereIn('events.id', $this->openReportEntityIds()),
            'recently_rejected' => $query->whereState('status', Rejected::class)->where('events.updated_at', '>=', now()->subDays(7)),
            default => $query->whereState('status', Pending::class),
        };
    }

    /**
     * @return list<string>
     */
    private function openReportEntityIds(): array
    {
        return $this->cachedOpenReportEntityIds ??= app(ReportReader::class)->openEntityIds('event');
    }

    private function canReviewOccurrence(EventSubmission $record): bool
    {
        $reviewer = auth()->user();

        return $reviewer instanceof User
            && ($record->submitter_type !== $reviewer->getMorphClass()
                || (string) $record->submitter_id !== (string) $reviewer->getKey());
    }

    private function submitterLabel(EventSubmission $record): string
    {
        $submitter = $record->submitter;

        if (! $submitter instanceof Model) {
            return (string) __('Guest');
        }

        $name = $submitter->getAttribute('name');

        if (is_string($name) && trim($name) !== '') {
            return $name;
        }

        $email = $submitter->getAttribute('email');

        return is_string($email) && $email !== '' ? $email : (string) __('Guest');
    }

    private function submissionValueText(mixed $value): string
    {
        if ($value === null || $value === '') {
            return (string) __('Empty');
        }

        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($encoded) ? $encoded : (string) __('Empty');
    }

    private function validationMessage(ValidationException $exception, string $fallback): string
    {
        $message = collect($exception->errors())->flatten()->first();

        return is_string($message) && $message !== '' ? $message : $fallback;
    }

    private function notifyDanger(string $title): void
    {
        Notification::make()
            ->title($title !== '' ? $title : __('Could not review this submission.'))
            ->danger()
            ->send();
    }

    protected function normalizeActiveTab(string $tab): string
    {
        if (in_array($tab, self::AVAILABLE_TABS, true)) {
            return $tab;
        }

        return 'pending';
    }

    #[\Override]
    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'admin', 'moderator']) ?? false;
    }
}
