<?php

namespace App\Actions\Events;

use AIArmada\Events\Enums\RegistrationMode;
use AIArmada\Events\Models\EventAccessPolicy;
use App\Models\Event;
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
        $requestedRegistrationRequired = filter_var(
            $state['registration_required'] ?? false,
            FILTER_VALIDATE_BOOL,
            FILTER_NULL_ON_FAILURE,
        ) ?? false;
        $requestedRegistrationMode = $requestedRegistrationRequired
            ? RegistrationMode::Required->value
            : RegistrationMode::None->value;

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

        $event->forceFill([
            'registration_mode' => $modeToPersist,
        ])->save();

        $event->accessPolicy()->updateOrCreate(
            ['event_id' => $event->id],
            [
                'registration_required' => $registrationRequiredToPersist,
                'walk_in_allowed' => ! $registrationRequiredToPersist,
            ]
        );

        $rawLanguageIds = is_array($state['languages'] ?? null) ? $state['languages'] : [];

        $languageIds = collect($rawLanguageIds)
            ->filter(fn (mixed $id): bool => filled($id))
            ->map(fn (mixed $id): string => (string) $id)
            ->values()
            ->all();

        $event->syncLanguages($languageIds);

        $classificationState = [
            'domain_tags' => is_array($state['domain_tags'] ?? null) ? $state['domain_tags'] : [],
            'discipline_tags' => is_array($state['discipline_tags'] ?? null) ? $state['discipline_tags'] : [],
            'source_tags' => is_array($state['source_tags'] ?? null) ? $state['source_tags'] : [],
            'issue_tags' => is_array($state['issue_tags'] ?? null) ? $state['issue_tags'] : [],
            'taxonomy_term_ids' => is_array($state['taxonomy_term_ids'] ?? null) ? $state['taxonomy_term_ids'] : [],
        ];
        if (array_key_exists('event_category_ids', $state)) {
            $classificationState['event_category_ids'] = is_array($state['event_category_ids']) ? $state['event_category_ids'] : [];
        }
        app(SyncEventClassificationsAction::class)->handle($event, $classificationState);

        if ($syncKeyPeople) {
            $this->eventKeyPersonSyncService->sync(
                $event,
                is_array($state['persons'] ?? null) ? $state['persons'] : [],
                $this->canonicalKeyPeople($state['other_key_people'] ?? []),
            );
        }

        return [
            'registration_mode' => $modeToPersist,
            'registration_mode_locked' => $registrationModeLocked,
        ];
    }

    protected function resolveRegistrationMode(Event $event): RegistrationMode
    {
        return $event->resolvedRegistrationMode();
    }

    protected function resolveRegistrationRequired(Event $event): bool
    {
        $accessPolicy = $event->accessPolicy;

        if (! $accessPolicy instanceof EventAccessPolicy) {
            return false;
        }

        return (bool) $accessPolicy->registration_required;
    }

    /** @return list<array<string, mixed>> */
    private function canonicalKeyPeople(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        return collect($rows)->filter(static fn (mixed $row): bool => is_array($row))->map(static fn (array $row): array => [
            'role_code' => $row['role_code'] ?? null,
            'involveable_type' => $row['involveable_type'] ?? null,
            'involveable_id' => $row['involveable_id'] ?? null,
            'display_name' => $row['display_name'] ?? null,
            'visibility' => $row['visibility'] ?? 'public',
            'notes' => $row['notes'] ?? null,
        ])->values()->all();
    }
}
