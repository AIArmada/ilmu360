<?php

namespace App\Actions\Events;

use App\Enums\RegistrationMode;
use App\Models\Event;
use App\Models\EventSettings;
use App\Services\EventKeyPersonSyncService;
use Lorisleiva\Actions\Concerns\AsAction;

class SyncEventResourceRelationsAction
{
    use AsAction;

    public function __construct(
        private readonly EventKeyPersonSyncService $eventKeyPersonSyncService,
    ) {}

    /**
     * @param  array<string, mixed>  $state
     * @return array{registration_mode: string, registration_mode_locked: bool}
     */
    public function handle(
        Event $event,
        array $state,
        bool $lockRegistrationMode = true,
        bool $syncKeyPeople = true,
    ): array {
        $eventHasRegistrations = $event->registrations()->exists();
        $requestedRegistrationMode = is_string($state['registration_mode'] ?? null) && $state['registration_mode'] !== ''
            ? $state['registration_mode']
            : RegistrationMode::Event->value;
        $requestedRegistrationRequired = filter_var(
            $state['registration_required'] ?? false,
            FILTER_VALIDATE_BOOL,
            FILTER_NULL_ON_FAILURE,
        ) ?? false;

        $currentRegistrationMode = $this->resolveRegistrationMode($event)->value;
        $currentRegistrationRequired = $this->resolveRegistrationRequired($event);
        $registrationModeLocked = $lockRegistrationMode
            && $eventHasRegistrations
            && $requestedRegistrationMode !== $currentRegistrationMode;
        $registrationRequiredLocked = $eventHasRegistrations
            && $requestedRegistrationRequired !== $currentRegistrationRequired;

        $modeToPersist = $registrationModeLocked ? $currentRegistrationMode : $requestedRegistrationMode;
        $registrationRequiredToPersist = $registrationRequiredLocked
            ? $currentRegistrationRequired
            : $requestedRegistrationRequired;

        $event->settings()->updateOrCreate(
            ['event_id' => $event->id],
            [
                'registration_required' => $registrationRequiredToPersist,
                'registration_mode' => $modeToPersist,
            ]
        );

        $rawLanguageIds = is_array($state['languages'] ?? null) ? $state['languages'] : [];

        $languageIds = collect($rawLanguageIds)
            ->filter(fn (mixed $id): bool => filled($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        $event->syncLanguages($languageIds);

        $domainTagIds = is_array($state['domain_tags'] ?? null) ? $state['domain_tags'] : [];
        $disciplineTagIds = is_array($state['discipline_tags'] ?? null) ? $state['discipline_tags'] : [];
        $sourceTagIds = is_array($state['source_tags'] ?? null) ? $state['source_tags'] : [];
        $issueTagIds = is_array($state['issue_tags'] ?? null) ? $state['issue_tags'] : [];

        $tagIds = collect(array_merge($domainTagIds, $disciplineTagIds, $sourceTagIds, $issueTagIds))
            ->filter(fn (mixed $id): bool => filled($id))
            ->map(fn (mixed $id): string => (string) $id)
            ->unique()
            ->values()
            ->all();

        $event->auditSync('tags', $tagIds, true, ['tags.id', 'tags.name', 'tags.type']);

        if ($syncKeyPeople) {
            $this->eventKeyPersonSyncService->sync(
                $event,
                is_array($state['speakers'] ?? null) ? $state['speakers'] : [],
                is_array($state['other_key_people'] ?? null) ? $state['other_key_people'] : [],
            );
        }

        return [
            'registration_mode' => $modeToPersist,
            'registration_mode_locked' => $registrationModeLocked,
        ];
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

    protected function resolveRegistrationRequired(Event $event): bool
    {
        $settings = $event->settings;

        if (! $settings instanceof EventSettings) {
            return false;
        }

        return (bool) $settings->registration_required;
    }
}
