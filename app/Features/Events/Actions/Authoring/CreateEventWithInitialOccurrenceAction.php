<?php

namespace App\Features\Events\Actions\Authoring;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Enums\RegistrationMode;
use AIArmada\Events\Enums\ScheduleKind;
use App\Features\Events\Api\AdvancedEventCreator;
use App\Features\Events\Api\EventAuthoring;
use App\Features\Events\Api\EventScheduleInput;
use App\Features\Organizations\Api\OrganizationAccess;
use App\Features\Places\Api\SpaceEligibility;
use App\Features\Events\Models\Event;
use App\Platform\Media\MediaCollectionSync;
use App\Platform\Models\MorphModelResolver;
use App\Shared\Events\Enums\EventFormat;
use App\Shared\Events\Enums\TimingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Creates the event container and its first scheduled occurrence.
 *
 * Sessions are deliberately created beneath that occurrence; this action never
 * models an event as the child of another event.
 */
final readonly class CreateEventWithInitialOccurrenceAction implements AdvancedEventCreator
{
    public function __construct(
        private MediaCollectionSync $mediaSync,
        private EventAuthoring $authoring,
        private SpaceEligibility $spaceEligibilityResolver,
        private OrganizationAccess $organizationAccess,
    ) {}

    /**
     * @param  array<string, mixed>  $form
     */
    public function create(
        string $actorId,
        array $form,
        string $startsAt,
        string $endsAt,
        string $timezone,
        ?string $primaryOrganizerType,
        ?string $primaryOrganizerId,
        ?string $locationInstitutionId,
        ?string $organizationId = null,
        ?string $locationVenueId = null,
    ): string {
        $user = MorphModelResolver::resolve('user')::query()->findOrFail($actorId);
        $organization = $organizationId !== null
            ? MorphModelResolver::resolve('organization')::query()->findOrFail($organizationId)
            : null;
        $primaryOrganizer = match ($primaryOrganizerType) {
            null => null,
            'institution', 'person' => MorphModelResolver::resolve($primaryOrganizerType)::query()->findOrFail($primaryOrganizerId),
            default => throw new InvalidArgumentException("Unsupported organizer type [{$primaryOrganizerType}]."),
        };
        $startsAt = Carbon::parse($startsAt);
        $endsAt = Carbon::parse($endsAt);

        return DB::transaction(function () use ($user, $form, $startsAt, $endsAt, $timezone, $primaryOrganizer, $locationInstitutionId, $locationVenueId, $organization): string {
            if ($organization !== null) {
                $this->organizationAccess->authorizeCreateEvent(
                    (string) $user->getKey(),
                    (string) $organization->getKey(),
                );
            }

            $owner = $organization ?? $user;
            $eventFormat = (string) ($form['event_format'] ?? $form['default_event_format'] ?? EventFormat::Physical->value);
            $categoryIds = is_array($form['event_category_ids'] ?? null)
                ? $form['event_category_ids']
                : (is_array($form['default_event_category_ids'] ?? null) ? $form['default_event_category_ids'] : []);
            $spaceIds = array_values(array_filter((array) ($form['space_ids'] ?? []), is_string(...)));
            $locationType = (string) ($form['location_type'] ?? 'institution');
            $venueId = $locationType === 'venue' && is_string($locationVenueId) ? $locationVenueId : null;

            if ($spaceIds !== [] && $venueId !== null) {
                $this->spaceEligibilityResolver->validateVenueSelection($venueId, $spaceIds);
            }

            if ($spaceIds !== [] && is_string($locationInstitutionId) && $locationInstitutionId !== '') {
                $this->spaceEligibilityResolver->validateInstitutionSelection($locationInstitutionId, $spaceIds);
            }

            $personSlugSegments = ($primaryOrganizer instanceof Model && $primaryOrganizer->getMorphClass() === 'person')
                ? $this->authoring->personSlugSegmentsForPersonIds([(string) $primaryOrganizer->getKey()])
                : [];

            $eventId = $this->authoring->createEventShell([
                'owner_type' => $owner->getMorphClass(),
                'owner_id' => (string) $owner->getKey(),
                'created_by_type' => $user->getMorphClass(),
                'created_by_id' => (string) $user->getKey(),
                'title' => (string) $form['title'],
                'slug' => $this->authoring->eventSlug(
                    (string) $form['title'],
                    $startsAt,
                    $timezone,
                    null,
                    $personSlugSegments,
                ),
                'description' => $form['description'] ?? null,
                'timezone' => $timezone,
                'institution_id' => $locationInstitutionId,
                'default_venue_id' => $venueId,
                'gender' => $form['gender'] ?? null,
                'age_group' => $form['age_group'] ?? null,
                'children_allowed' => $form['children_allowed'] ?? null,
                'is_muslim_only' => $form['is_muslim_only'] ?? null,
                'delivery_mode' => $eventFormat,
                'event_url' => $form['event_url'] ?? null,
                'live_url' => $form['live_url'] ?? null,
                'visibility' => (string) $form['visibility'],
                'registration_mode' => empty($form['registration_required'])
                    ? RegistrationMode::None->value
                    : RegistrationMode::Required->value,
                'status' => 'draft',
                'metadata' => [
                    'advanced_first_session' => [
                        'event_date' => $form['event_date'] ?? null,
                        'prayer_time' => $form['prayer_time'] ?? null,
                        'custom_time' => $form['custom_time'] ?? null,
                        'end_time' => $form['end_time'] ?? null,
                    ],
                    'registration' => [
                        'check_in_enabled' => (bool) ($form['check_in_enabled'] ?? true),
                        'participant_identity' => (string) ($form['participant_identity'] ?? 'none'),
                        'refunds_enabled' => (bool) ($form['refunds_enabled'] ?? config('events.features.commerce.refunds_enabled_by_default', false)),
                    ],
                ],
            ]);

            /** @var Event $event */
            $event = Event::query()->withoutOwnerScope()->findOrFail($eventId);

            OwnerContext::withOwner($owner, function () use ($event, $form, $categoryIds, $venueId, $spaceIds, $startsAt, $endsAt, $timezone, $primaryOrganizer): void {
                $this->authoring->syncEventClassifications($event->getMorphClass(), (string) $event->getKey(), [
                    'event_category_ids' => $categoryIds,
                    'domain_tags' => (array) ($form['domain_tags'] ?? []),
                    'discipline_tags' => (array) ($form['discipline_tags'] ?? []),
                    'source_tags' => (array) ($form['source_tags'] ?? []),
                    'issue_tags' => (array) ($form['issue_tags'] ?? []),
                ]);

                $event->syncLocation($venueId, $spaceIds);

                if (is_array($form['languages'] ?? null) && $form['languages'] !== []) {
                    $event->syncLanguages($form['languages']);
                }

                if (array_key_exists('references', $form)) {
                    $this->authoring->syncEventReferences(
                        (string) $event->getKey(),
                        array_values(array_filter(
                            (array) $form['references'],
                            is_string(...),
                        )),
                    );
                }

                $this->authoring->syncEventSchedule((string) $event->getKey(), new EventScheduleInput(
                    scheduleKind: ScheduleKind::Single->value,
                    startsAt: $startsAt->toIso8601String(),
                    endsAt: $endsAt->toIso8601String(),
                    timezone: $timezone,
                    timingMode: TimingMode::Absolute->value,
                ));

                $this->authoring->createInitialAccessPolicy(
                    (string) $event->getKey(),
                    (bool) $form['registration_required'],
                );

                $this->authoring->syncPrimaryOrganizer(
                    (string) $event->getKey(),
                    $primaryOrganizer?->getMorphClass(),
                    $primaryOrganizer !== null ? (string) $primaryOrganizer->getKey() : null,
                );

                $this->authoring->syncEventKeyPeople(
                    (string) $event->getKey(),
                    array_values(array_filter((array) ($form['persons'] ?? []), is_string(...))),
                    $this->canonicalOtherKeyPeople($form['other_key_people'] ?? []),
                );

                $cover = $form['cover'] ?? null;
                $poster = $form['poster'] ?? null;
                $gallery = array_values(array_filter((array) ($form['gallery'] ?? []), $this->isUploadedFile(...)));

                if ($cover instanceof UploadedFile) {
                    $this->mediaSync->syncSingle($event, $cover, 'cover');
                }

                if ($poster instanceof UploadedFile) {
                    $this->mediaSync->syncSingle($event, $poster, 'poster');
                }

                if ($gallery !== []) {
                    $this->mediaSync->syncMultiple($event, $gallery, 'gallery');
                }
            });

            return (string) $event->getKey();
        });
    }

    private function isUploadedFile(mixed $file): bool
    {
        return $file instanceof UploadedFile;
    }

    /** @return list<array<string, mixed>> */
    private function canonicalOtherKeyPeople(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        return collect($rows)
            ->filter(fn (mixed $row): bool => is_array($row))
            ->map(static fn (array $row): array => [
                'role_code' => $row['role_code'] ?? null,
                'involveable_type' => filled($row['involveable_id'] ?? null) ? 'person' : null,
                'involveable_id' => $row['involveable_id'] ?? null,
                'display_name' => filled($row['involveable_id'] ?? null) ? null : ($row['display_name'] ?? null),
                'visibility' => $row['visibility'] ?? 'public',
                'notes' => $row['notes'] ?? null,
            ])
            ->values()
            ->all();
    }
}
