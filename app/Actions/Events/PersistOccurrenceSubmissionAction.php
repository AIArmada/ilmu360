<?php

declare(strict_types=1);

namespace App\Actions\Events;

use AIArmada\Events\Actions\CreateEventOccurrenceAction;
use AIArmada\Events\Actions\SyncEventLanguagesAction;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventTimeExpression;
use AIArmada\Events\Models\VenueSpaceType;
use AIArmada\Events\Support\Normalization\EventContentNormalizer;
use App\Data\Events\ValidatedEventSubmission;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventPrayerTime;
use App\Enums\EventVisibility;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Language;
use App\Models\Space;
use App\Services\EventKeyPersonSyncService;
use App\Services\Prayer\HardcodedPrayerFallback;
use App\Services\PrayerTimeExpressionResolver;
use App\Support\Events\AdminEventTimeMapper;
use App\Support\Submission\SubmissionRelationSync;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;

final readonly class PersistOccurrenceSubmissionAction
{
    public function __construct(
        private CreateEventOccurrenceAction $createOccurrence,
        private GenerateEventSlugAction $generateEventSlug,
        private EventKeyPersonSyncService $eventKeyPersonSync,
        private SyncEventClassificationsAction $syncClassifications,
        private SyncEventLanguagesAction $syncLanguages,
        private EventContentNormalizer $contentNormalizer,
        private SubmissionRelationSync $relationSync,
    ) {}

    /**
     * @param  array{key: string, institution_id: string, category_ids: list<string>, prayer_slot: string, title: string}|null  $groupIdentity
     * @param  (callable(HasMedia): void)|null  $persistRelationships
     */
    public function handle(
        Event $event,
        ValidatedEventSubmission $submission,
        bool $pendingReview,
        ?array $groupIdentity = null,
        ?callable $persistRelationships = null,
    ): EventOccurrence {
        $state = $submission->state;
        $requestedVisibility = $this->enumValue($state['visibility'] ?? EventVisibility::Public->value);
        $occurrenceMetadata = $this->occurrenceMetadata($state['description'] ?? null);

        if ($groupIdentity !== null) {
            $occurrenceMetadata['submission_group'] = [
                'key' => $groupIdentity['key'],
                'prayer_slot' => $groupIdentity['prayer_slot'],
            ];
        }

        $occurrenceMetadata['public_submission'] = [
            'requested_visibility' => $requestedVisibility,
        ];

        $occurrence = $this->createOccurrence->handle($event, [
            'title' => $state['title'],
            'slug' => $this->uniqueOccurrenceSlug($event, $submission),
            'starts_at' => CarbonImmutable::createFromInterface($submission->startsAt)->setTimezone('UTC'),
            'ends_at' => $submission->endsAt instanceof \DateTimeInterface
                ? CarbonImmutable::createFromInterface($submission->endsAt)->setTimezone('UTC')
                : null,
            'timezone' => $submission->timezone,
            'status' => $pendingReview ? EventOccurrence::DRAFT : EventOccurrence::SCHEDULED,
            'visibility' => $pendingReview ? Event::PRIVATE : $requestedVisibility,
            'delivery_mode' => $this->enumValue($state['event_format'] ?? EventFormat::Physical->value),
            'metadata' => $occurrenceMetadata,
        ]);

        $this->persistOccurrenceDetails($event, $occurrence, $submission);

        if ($persistRelationships !== null) {
            $persistRelationships($occurrence);
        }

        return $occurrence;
    }

    private function uniqueOccurrenceSlug(Event $event, ValidatedEventSubmission $submission): string
    {
        $state = $submission->state;
        $baseSlug = $this->generateEventSlug->handle(
            (string) $state['title'],
            $state['event_date'] ?? null,
            $submission->timezone,
            null,
            $submission->personSlugSegments,
        );
        $slug = $baseSlug;
        $suffix = 2;

        while (EventOccurrence::query()
            ->withoutGlobalScopes()
            ->where('event_id', $event->getKey())
            ->where('slug', $slug)
            ->exists()) {
            $slug = Str::limit($baseSlug, 240 - strlen((string) $suffix), '').'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    /** @return array<string, mixed> */
    private function occurrenceMetadata(mixed $description): array
    {
        if (is_array($description) && $description !== []) {
            return ['description_localized' => $description];
        }

        if (is_string($description)) {
            $normalized = $this->contentNormalizer->normalizeDescription($description);

            if (is_string($normalized) && trim($normalized) !== '') {
                return ['description_localized' => [app()->getLocale() => $normalized]];
            }
        }

        return [];
    }

    private function persistOccurrenceDetails(
        Event $event,
        EventOccurrence $occurrence,
        ValidatedEventSubmission $submission,
    ): void {
        $state = $submission->state;
        $scope = [
            'event_id' => $event->getKey(),
            'event_occurrence_id' => $occurrence->getKey(),
            'event_session_id' => null,
        ];

        foreach (['event_url' => 'external', 'live_url' => 'streaming'] as $field => $type) {
            if (filled($state[$field] ?? null)) {
                $occurrence->links()->create($scope + [
                    'link_type' => $type,
                    'url' => $state[$field],
                    'visibility' => 'public',
                ]);
            }
        }

        $occurrence->audiences()->create($scope + [
            'audience_type' => 'gender',
            'value' => $state['gender'] ?? EventGenderRestriction::All->value,
        ]);

        foreach ($state['age_group'] ?? [EventAgeGroup::AllAges->value] as $order => $ageGroup) {
            $occurrence->audiences()->create($scope + [
                'audience_type' => 'age_group',
                'value' => $ageGroup,
                'sort_order' => $order,
            ]);
        }

        if ($state['is_muslim_only'] ?? false) {
            $occurrence->audiences()->create($scope + [
                'audience_type' => 'religion',
                'value' => 'muslim_only',
            ]);
        }

        $occurrence->audienceProfiles()->create($scope + [
            'is_child_friendly' => $state['children_allowed'] ?? true,
        ]);

        $this->persistPrayerExpression($occurrence, $scope, $submission);

        if (($state['event_format'] ?? EventFormat::Physical->value) !== EventFormat::Online->value) {
            $this->persistOccurrenceLocations($occurrence, $scope, $submission);
        }

        $this->eventKeyPersonSync->syncOccurrence(
            $occurrence,
            $this->relationSync->normalizePersonIds($state['persons'] ?? []),
            $this->relationSync->canonicalKeyPeople($state['other_key_people'] ?? []),
        );
        $this->eventKeyPersonSync->syncOccurrenceOrganizer($occurrence, $submission->primaryOrganizer);

        if (is_array($state['languages'] ?? null) && $state['languages'] !== []) {
            $this->syncLanguages->handle(
                $occurrence,
                Language::codesForIds($state['languages']),
                metadata: ['source' => 'commerce_languages'],
            );
        }

        $this->syncClassifications->handle($occurrence, $state);
        $this->relationSync->syncOccurrenceReferences($event, $occurrence, $state['references'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    private function persistPrayerExpression(EventOccurrence $occurrence, array $scope, ValidatedEventSubmission $submission): void
    {
        $prayerTime = $submission->prayerTime;

        if (! $prayerTime instanceof EventPrayerTime || $prayerTime->isCustomTime()) {
            return;
        }

        $offset = $submission->prayerOffset ?? 5;
        $expressionMetadata = [
            'submission_group' => ['prayer_slot' => $prayerTime->value],
            'prayer' => array_filter([
                'source' => $submission->prayerSource ?? HardcodedPrayerFallback::SOURCE,
                'fetched_at' => $submission->prayerFetchedAt ?? now()->toIso8601String(),
                'country' => $submission->prayerCountry,
                'zone' => $submission->prayerZone,
                'prayer_date' => AdminEventTimeMapper::normalizeEventDateString(
                    $submission->state['event_date'] ?? null,
                    $submission->timezone,
                ),
                'lat' => $submission->prayerLat,
                'lng' => $submission->prayerLng,
                'venue_id' => $submission->targetVenueId,
                'institution_id' => $submission->targetInstitutionId,
            ], static fn (mixed $value): bool => $value !== null),
        ];

        EventTimeExpression::query()->create($scope + [
            'time_mode' => 'prayer_relative',
            'anchor_type' => 'prayer',
            'anchor_code' => $submission->prayerReference,
            'relation' => $offset < 0 ? 'before' : 'after',
            'offset_minutes' => abs($offset),
            'display_label' => $submission->prayerDisplayText,
            'resolver_class' => PrayerTimeExpressionResolver::class,
            'metadata' => $expressionMetadata,
            'resolved_starts_at' => $submission->startsAt,
            'resolved_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    private function persistOccurrenceLocations(
        EventOccurrence $occurrence,
        array $scope,
        ValidatedEventSubmission $submission,
    ): void {
        $spaceIds = is_array($submission->state['space_ids'] ?? null)
            ? array_values(array_filter($submission->state['space_ids'], is_string(...)))
            : [];
        $institution = $submission->targetInstitutionId === null
            ? null
            : Institution::query()->find($submission->targetInstitutionId);
        $spaces = Space::query()->whereKey($spaceIds)->get(['id', 'name', 'space_type'])->keyBy('id');
        $spaceTypeIds = VenueSpaceType::query()
            ->whereIn('code', $spaces->pluck('space_type')->filter()->unique())
            ->pluck('id', 'code');
        $locationIds = $spaceIds === [] ? [null] : $spaceIds;

        foreach ($locationIds as $order => $spaceId) {
            $space = $spaces->get($spaceId);
            $occurrence->locations()->create($scope + [
                'location_role' => $order === 0 ? 'primary' : 'additional',
                'locationable_type' => $institution?->getMorphClass(),
                'locationable_id' => $institution?->getKey(),
                'venue_id' => $submission->targetVenueId,
                'venue_space_id' => $spaceId,
                'venue_space_type_id' => $space === null ? null : $spaceTypeIds->get($space->space_type),
                'space_name_snapshot' => $space?->name,
                'visibility' => 'public',
                'status' => 'active',
                'sort_order' => $order,
            ]);
        }
    }

    private function enumValue(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : $value;
    }
}
