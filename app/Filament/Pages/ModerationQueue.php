<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Events\EventResource;
use App\Models\Event;
use App\Services\ModerationService;
use App\States\EventStatus\Approved;
use App\States\EventStatus\NeedsChanges;
use App\States\EventStatus\Pending;
use App\States\EventStatus\Rejected;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use UnitEnum;

class ModerationQueue extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $navigationLabel = 'Moderation Queue';

    protected static string|UnitEnum|null $navigationGroup = 'Moderation';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.moderation-queue';

    /**
     * @var list<string>
     */
    protected const AVAILABLE_TABS = [
        'pending',
        'needs_changes',
        'reports',
        'recently_rejected',
    ];

    #[Url(as: 'tab')]
    public string $activeTab = 'pending';

    public function mount(): void
    {
        $this->activeTab = $this->normalizeActiveTab($this->activeTab);
    }

    public function setActiveTab(string $tab): void
    {
        $this->activeTab = $this->normalizeActiveTab($tab);

        $this->resetPage();
        $this->flushCachedTableRecords();
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
        return [
            'pending' => [
                'label' => 'Pending',
                'icon' => 'heroicon-o-clock',
                'count' => Event::whereState('status', Pending::class)->count(),
                'badgeColor' => 'warning',
            ],
            'needs_changes' => [
                'label' => 'Needs Changes',
                'icon' => 'heroicon-o-pencil-square',
                'count' => Event::whereState('status', NeedsChanges::class)->count(),
                'badgeColor' => 'info',
            ],
            'reports' => [
                'label' => 'Reports',
                'icon' => 'heroicon-o-flag',
                'count' => Event::whereHas('reports', fn (Builder $query) => $query->where('status', 'open'))->count(),
                'badgeColor' => 'danger',
            ],
            'recently_rejected' => [
                'label' => 'Recently Rejected',
                'icon' => 'heroicon-o-x-circle',
                'count' => Event::whereState('status', Rejected::class)->where('updated_at', '>=', now()->subDays(7))->count(),
                'badgeColor' => 'gray',
            ],
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->getTableQuery())
            ->columns([
                IconColumn::make('is_priority')
                    ->label('Priority')
                    ->boolean()
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
                    ->formatStateUsing(fn ($state): string => $state ? Str::title(str_replace('_', ' ', (string) $state)) : 'None')
                    ->color(fn ($state): string => match ((string) $state) {
                        'verified' => 'success',
                        'pending' => 'warning',
                        'rejected' => 'danger',
                        'unverified' => 'gray',
                        default => 'gray',
                    }),
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
                        'unverified' => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('speakers_status')
                    ->label('Speakers Status')
                    ->badge()
                    ->state(function (Event $record): string {
                        $total = $record->speakers->count();

                        if ($total === 0) {
                            return 'None';
                        }

                        $unverified = $record->speakers->where('status', '!=', 'verified')->count();

                        return $unverified === 0 ? 'All verified' : $unverified.' unverified';
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

                        $unverified = $record->references->where('status', '!=', 'verified')->count();

                        return $unverified === 0 ? 'All verified' : $unverified.' unverified';
                    })
                    ->tooltip(function (Event $record): ?string {
                        $pendingReferences = $record->references
                            ->where('status', '!=', 'verified')
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
                TextColumn::make('latestModerationReview.reason_code')
                    ->label('Latest Reason')
                    ->formatStateUsing(fn (?string $state): string => filled($state) ? Str::title(str_replace('_', ' ', $state)) : '-')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('latestModerationReview.note')
                    ->label('Latest Moderation Note')
                    ->limit(80)
                    ->tooltip(fn (Event $record): ?string => $record->latestModerationReview?->note)
                    ->placeholder('-')
                    ->wrap(),
                TextColumn::make('created_at')
                    ->label('Submitted')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('state_id')
                    ->label('State')
                    ->relationship('address.state', 'name'),
                SelectFilter::make('institution_id')
                    ->label('Institution')
                    ->relationship('institution', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Approve Event')
                    ->modalDescription('Are you sure you want to approve this event? It will be published and made searchable.')
                    ->form([
                        Textarea::make('note')
                            ->label('Note (optional)')
                            ->rows(3),
                    ])
                    ->action(function (Event $record, array $data, ModerationService $service): void {
                        $service->approve($record, auth()->user(), $data['note'] ?? null);
                    })
                    ->visible(fn (Event $record) => $record->status instanceof Pending),

                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->modalHeading('Reject Event')
                    ->modalDescription('This event will be rejected and removed from search.')
                    ->form([
                        Select::make('reason_code')
                            ->label('Reason')
                            ->options([
                                'incomplete_info' => 'Incomplete Information',
                                'duplicate' => 'Duplicate Event',
                                'inappropriate' => 'Inappropriate Content',
                                'spam' => 'Spam',
                                'wrong_category' => 'Wrong Category',
                                'inaccurate_details' => 'Inaccurate Details',
                                'missing_speaker' => 'Missing Speaker Information',
                                'missing_venue' => 'Missing Venue Information',
                                'other' => 'Other',
                            ])
                            ->required(),
                        Textarea::make('note')
                            ->label('Note to Submitter')
                            ->required()
                            ->rows(3),
                    ])
                    ->action(function (Event $record, array $data, ModerationService $service): void {
                        $service->reject($record, auth()->user(), $data['reason_code'], $data['note']);
                    })
                    ->visible(fn (Event $record) => $record->status instanceof Pending),

                Action::make('cancel')
                    ->label('Cancel')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Cancel Event')
                    ->modalDescription('This event will remain visible with a cancelled badge and notify users who saved or plan to attend.')
                    ->form([
                        Textarea::make('note')
                            ->label('Cancellation note (optional)')
                            ->rows(3),
                    ])
                    ->action(function (Event $record, array $data, ModerationService $service): void {
                        $service->cancel($record, auth()->user(), $data['note'] ?? null);
                    })
                    ->visible(fn (Event $record) => $record->status instanceof Pending
                        || $record->status instanceof Approved),

                Action::make('needs_changes')
                    ->label('Request Changes')
                    ->icon('heroicon-o-pencil-square')
                    ->color('warning')
                    ->modalHeading('Request Changes')
                    ->modalDescription('Specify what changes the submitter needs to make.')
                    ->form([
                        Select::make('reason_code')
                            ->label('Reason')
                            ->options([
                                'incomplete_info' => 'Incomplete Information',
                                'duplicate' => 'Duplicate Event',
                                'inappropriate' => 'Inappropriate Content',
                                'wrong_category' => 'Wrong Category',
                                'inaccurate_details' => 'Inaccurate Details',
                                'missing_speaker' => 'Missing Speaker Information',
                                'missing_venue' => 'Missing Venue Information',
                                'other' => 'Other',
                            ])
                            ->required(),
                        Textarea::make('note')
                            ->label('What needs to be changed?')
                            ->required()
                            ->rows(3),
                    ])
                    ->action(function (Event $record, array $data, ModerationService $service): void {
                        $service->requestChanges($record, auth()->user(), $data['reason_code'], $data['note']);
                    })
                    ->visible(fn (Event $record) => $record->status instanceof Pending),

                Action::make('reconsider')
                    ->label('Reconsider')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Reconsider Event')
                    ->modalDescription('Move this event back to pending for re-review.')
                    ->form([
                        Textarea::make('note')
                            ->label('Reason for reconsideration')
                            ->rows(3),
                    ])
                    ->action(function (Event $record, array $data, ModerationService $service): void {
                        $service->reconsider($record, auth()->user(), $data['note'] ?? null);
                    })
                    ->visible(fn (Event $record) => $record->status instanceof Rejected),

                Action::make('revert_to_draft')
                    ->label('Revert to Draft')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('Revert to Draft')
                    ->modalDescription('Move this event back to draft status.')
                    ->form([
                        Textarea::make('note')
                            ->label('Reason (optional)')
                            ->rows(3),
                    ])
                    ->action(function (Event $record, array $data, ModerationService $service): void {
                        $service->revertToDraft($record, auth()->user(), $data['note'] ?? null);
                    })
                    ->visible(fn (Event $record) => $record->status instanceof Rejected
                        || $record->status instanceof NeedsChanges),

                Action::make('return_to_pending')
                    ->label('Return to Pending')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Return to Pending')
                    ->modalDescription('Move this event back to pending for moderation review.')
                    ->action(function (Event $record, ModerationService $service): void {
                        $service->submitForModeration($record);
                    })
                    ->visible(fn (Event $record): bool => $record->status instanceof NeedsChanges),

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

    /**
     * @return Builder<Event>
     */
    protected function getTableQuery(): Builder
    {
        $query = Event::query()
            ->with(['institution', 'venue', 'speakers', 'references', 'address.state', 'latestModerationReview'])
            ->withCount([
                'reports as open_reports_count' => fn (Builder $reportQuery) => $reportQuery->where('status', 'open'),
            ]);

        $query = match ($this->activeTab) {
            'pending' => $query->whereState('status', Pending::class),
            'needs_changes' => $query->whereState('status', NeedsChanges::class),
            'reports' => $query->whereHas('reports', fn (Builder $reportQuery) => $reportQuery->where('status', 'open')),
            'recently_rejected' => $query->whereState('status', Rejected::class)->where('updated_at', '>=', now()->subDays(7)),
            default => $query->whereState('status', Pending::class),
        };

        return $query
            ->orderByRaw('CASE WHEN events.is_priority THEN 0 ELSE 1 END')
            ->orderBy('events.starts_at')
            ->orderByDesc('events.created_at');
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
        return auth()->user()?->hasAnyRole(['super_admin', 'moderator']) ?? false;
    }
}
