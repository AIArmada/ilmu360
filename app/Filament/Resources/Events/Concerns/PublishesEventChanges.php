<?php

namespace App\Filament\Resources\Events\Concerns;

use App\Actions\Events\PublishEventChangeAnnouncement;
use App\Enums\EventChangeType;
use App\Enums\EventVisibility;
use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

trait PublishesEventChanges
{
    protected function getPublishChangeAction(): Action
    {
        return Action::make('publish_change')
            ->label('Publish Change')
            ->icon(Heroicon::OutlinedMegaphone)
            ->color('warning')
            ->modalWidth(Width::Large)
            ->modalHeading('Publish Event Change')
            ->modalDescription('Publish an explicit public notice and notify committed users. Ordinary edits remain ordinary edits until this action is used.')
            ->schema([
                Select::make('type')
                    ->label('Change type')
                    ->options($this->eventChangeTypeOptions())
                    ->required()
                    ->live()
                    ->default(EventChangeType::Other->value),
                DateTimePicker::make('starts_at')
                    ->label('New start date and time')
                    ->seconds(false)
                    ->visible(fn (Get $get): bool => $this->isScheduleChangeType($get('type'))),
                DateTimePicker::make('ends_at')
                    ->label('New end date and time')
                    ->seconds(false)
                    ->visible(fn (Get $get): bool => $this->isScheduleChangeType($get('type'))),
                Select::make('venue_id')
                    ->label('New venue')
                    ->options(fn (): array => Venue::query()
                        ->orderBy('name')
                        ->limit(100)
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->preload()
                    ->visible(fn (Get $get): bool => $get('type') === EventChangeType::LocationChanged->value),
                Select::make('replacement_event_id')
                    ->label('Replacement event')
                    ->options(fn (): array => Event::query()
                        ->whereKeyNot($this->eventRecord()->getKey())
                        ->where('is_active', true)
                        ->whereIn('status', Event::PUBLIC_STATUSES)
                        ->whereIn('visibility', [EventVisibility::Public->value, EventVisibility::Unlisted->value])
                        ->orderByDesc('starts_at')
                        ->limit(100)
                        ->pluck('title', 'id')
                        ->all())
                    ->searchable()
                    ->preload()
                    ->visible(fn (Get $get): bool => in_array($get('type'), [
                        EventChangeType::Cancelled->value,
                        EventChangeType::Postponed->value,
                        EventChangeType::ReplacementLinked->value,
                    ], true)),
                Textarea::make('public_message')
                    ->label('Public message')
                    ->rows(4)
                    ->maxLength(2000)
                    ->required()
                    ->helperText('Shown on the public event page and included in notifications.'),
                Textarea::make('internal_note')
                    ->label('Internal note')
                    ->rows(3)
                    ->maxLength(2000),
                Toggle::make('notify')
                    ->label('Notify committed users and responsible managers')
                    ->default(true),
                Placeholder::make('summary')
                    ->label('Before publishing')
                    ->content(fn (Get $get): HtmlString => new HtmlString($this->publishChangeSummary($get))),
            ])
            ->action(function (array $data): void {
                $this->publishChangeAnnouncement($data);
            })
            ->visible(fn (): bool => auth()->user()?->can('publishChange', $this->eventRecord()) ?? false);
    }

    /**
     * @return array<string, string>
     */
    protected function eventChangeTypeOptions(): array
    {
        return collect(EventChangeType::cases())
            ->mapWithKeys(fn (EventChangeType $type): array => [$type->value => $type->label()])
            ->all();
    }

    protected function isScheduleChangeType(mixed $type): bool
    {
        return in_array($type, [
            EventChangeType::RescheduledEarlier->value,
            EventChangeType::RescheduledLater->value,
            EventChangeType::ScheduleChanged->value,
        ], true);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function publishChangeAnnouncement(array $data): void
    {
        $event = $this->eventRecord();
        $actor = auth()->user();

        abort_unless($actor instanceof User, 403);

        $type = EventChangeType::from((string) $data['type']);
        $replacementEvent = filled($data['replacement_event_id'] ?? null)
            ? Event::query()->find((string) $data['replacement_event_id'])
            : null;
        $changes = [];

        if ($this->isScheduleChangeType($type->value)) {
            foreach (['starts_at', 'ends_at'] as $field) {
                if (filled($data[$field] ?? null)) {
                    $changes[$field] = $data[$field];
                }
            }
        }

        if ($type === EventChangeType::LocationChanged && filled($data['venue_id'] ?? null)) {
            $changes['venue_id'] = (string) $data['venue_id'];
            $changes['institution_id'] = null;
            $changes['space_id'] = null;
        }

        app(PublishEventChangeAnnouncement::class)->handle(
            event: $event,
            actor: $actor,
            type: $type,
            publicMessage: $data['public_message'] ?? null,
            internalNote: $data['internal_note'] ?? null,
            replacementEvent: $replacementEvent,
            changes: $changes,
            notify: (bool) ($data['notify'] ?? true),
        );

        $event->refresh();

        $this->refreshFormData(['status', 'schedule_state', 'starts_at', 'ends_at', 'venue_id']);

        Notification::make()
            ->title('Event change published')
            ->success()
            ->send();
    }

    protected function publishChangeSummary(Get $get): string
    {
        $type = EventChangeType::tryFrom((string) $get('type'));
        $label = e($type?->label() ?? __('Change'));

        return <<<HTML
<div class="space-y-2 text-sm text-gray-600 dark:text-gray-300">
    <p><strong>{$label}</strong> will create a public source-of-truth notice for this event.</p>
    <p>Recipients are committed users plus the submitter and responsible event, institution, speaker, and admin managers.</p>
</div>
HTML;
    }

    /**
     * @param  list<string>  $changedFields
     */
    protected function notifySensitiveEditsMayNeedAnnouncement(array $changedFields): void
    {
        $sensitiveFields = [
            'title',
            'starts_at',
            'ends_at',
            'timezone',
            'institution_id',
            'venue_id',
            'space_id',
            'organizer_type',
            'organizer_id',
            'event_url',
            'live_url',
            'recording_url',
        ];

        $fields = array_values(array_intersect($sensitiveFields, $changedFields));

        if ($fields === []) {
            return;
        }

        Notification::make()
            ->title('Publish a change announcement?')
            ->body('Sensitive event details changed: '.collect($fields)->map(fn (string $field): string => Str::headline($field))->implode(', ').'. Use the Publish Change action if committed users should be notified.')
            ->warning()
            ->persistent()
            ->send();
    }

    /**
     * @return list<string>
     */
    protected function sensitiveRelatedChangeFields(Event $event): array
    {
        $before = $this->previousRelatedSnapshotForSensitiveChangePrompt();
        $after = $this->currentRelatedSnapshotForSensitiveChangePrompt($event);
        $fields = [];

        foreach (['speakers', 'references'] as $field) {
            if ($this->snapshotIds($before[$field] ?? []) !== $this->snapshotIds($after[$field] ?? [])) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    protected function previousRelatedSnapshotForSensitiveChangePrompt(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function currentRelatedSnapshotForSensitiveChangePrompt(Event $event): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    private function snapshotIds(mixed $records): array
    {
        if (! is_array($records)) {
            return [];
        }

        return collect($records)
            ->map(fn (mixed $record): ?string => is_array($record) && isset($record['id']) ? (string) $record['id'] : null)
            ->filter()
            ->values()
            ->all();
    }

    abstract protected function eventRecord(): Event;
}
