<?php

namespace App\Actions\Events;

use AIArmada\Events\Enums\RegistrationMode;
use AIArmada\Events\Enums\ScheduleKind;
use AIArmada\Organizations\Models\Organization;
use App\Contracts\SpaceEligibilityResolver;
use App\Enums\EventFormat;
use App\Enums\TimingMode;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\User;
use App\Services\EventKeyPersonSyncService;
use App\Support\Authz\OrganizationEventAccess;
use App\Support\Media\ModelMediaSyncService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Creates the event container and its first scheduled occurrence.
 *
 * Sessions are deliberately created beneath that occurrence; this action never
 * models an event as the child of another event.
 */
class CreateAdvancedEventAction
{
    use AsAction;

    public function __construct(
        private readonly ModelMediaSyncService $mediaSync,
        private readonly EventKeyPersonSyncService $eventKeyPersonSync,
        private readonly SpaceEligibilityResolver $spaceEligibilityResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $form
     */
    public function handle(
        User $user,
        array $form,
        Carbon $startsAt,
        Carbon $endsAt,
        string $timezone,
        Institution|Person|null $primaryOrganizer,
        ?string $locationInstitutionId,
        ?Organization $organization = null,
        ?string $locationVenueId = null,
    ): Event {
        return DB::transaction(function () use ($user, $form, $startsAt, $endsAt, $timezone, $primaryOrganizer, $locationInstitutionId, $locationVenueId, $organization): Event {
            if ($organization instanceof Organization) {
                app(OrganizationEventAccess::class)->authorizeCreate($user, $organization);
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

            $personSlugSegments = $primaryOrganizer instanceof Person
                ? app(GenerateEventSlugAction::class)->personSlugSegmentsForPersonIds([(string) $primaryOrganizer->getKey()])
                : [];

            $event = Event::query()->create([
                'owner_type' => $owner->getMorphClass(),
                'owner_id' => $owner->getKey(),
                'created_by_type' => $user->getMorphClass(),
                'created_by_id' => $user->getKey(),
                'title' => (string) $form['title'],
                'slug' => app(GenerateEventSlugAction::class)->handle(
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
                ],
            ]);

            app(SyncEventClassificationsAction::class)->handle($event, [
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
                $event->references()->sync(array_values(array_filter(
                    (array) $form['references'],
                    is_string(...),
                )));
            }

            app(SyncEventScheduleAction::class)->execute(
                event: $event,
                scheduleKind: ScheduleKind::Single,
                startsAt: $startsAt,
                endsAt: $endsAt,
                timezone: $timezone,
                timingMode: TimingMode::Absolute,
            );

            $event->accessPolicy()->create([
                'registration_required' => (bool) $form['registration_required'],
                'walk_in_allowed' => ! (bool) $form['registration_required'],
            ]);

            $event->setPrimaryOrganizer($primaryOrganizer);

            $this->eventKeyPersonSync->sync(
                $event,
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

            return $event;
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
