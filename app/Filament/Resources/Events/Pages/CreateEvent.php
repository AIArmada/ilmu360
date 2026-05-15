<?php

namespace App\Filament\Resources\Events\Pages;

use App\Actions\Events\GenerateEventSlugAction;
use App\Actions\Events\SyncEventResourceRelationsAction;
use App\Filament\Pages\Concerns\AuditsRelatedStateChanges;
use App\Filament\Resources\Events\EventResource;
use App\Models\Event;
use App\Models\Reference;
use App\Models\Series;
use App\Models\Speaker;
use App\Support\Events\AdminEventTimeMapper;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;

class CreateEvent extends CreateRecord
{
    use AuditsRelatedStateChanges;

    protected static string $resource = EventResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    #[\Override]
    protected function mutateFormDataBeforeCreate(array $data): array
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
            (string) ($data['title'] ?? 'Event'),
            $data['event_date'] ?? $data['starts_at'] ?? null,
            is_string($data['timezone'] ?? null) ? $data['timezone'] : null,
            null,
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

    protected function afterCreate(): void
    {
        $event = $this->eventRecord();

        app(SyncEventResourceRelationsAction::class)->handle(
            $event,
            $this->form->getState(),
            lockRegistrationMode: false,
            syncKeyPeople: true,
        );

        $this->auditRelatedStateChanges($event, 'relations_created');
    }

    protected function eventRecord(): Event
    {
        $record = $this->getRecord();

        if (! $record instanceof Event) {
            throw new \RuntimeException('Expected Filament record to be an Event instance.');
        }

        return $record;
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
}
