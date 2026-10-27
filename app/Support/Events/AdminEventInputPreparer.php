<?php

namespace App\Support\Events;

use App\Actions\Events\Authoring\GenerateEventSlugAction;
use App\Contracts\EventCategoryCatalog;
use App\Contracts\EventCategoryPolicyResolver;
use App\Contracts\SpaceEligibilityResolver;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventKeyPersonRole;
use App\Enums\EventVisibility;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Support\Submission\SubmissionTimingPolicy;
use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * @phpstan-type PreparedAdminEventInput array{
 *     state: array<string, mixed>,
 *     record_state: array<string, mixed>,
 *     institution_id: string|null,
 *     venue_id: string|null,
 *     space_ids: list<string>,
 *     organizer_id: string|null,
 *     primary_organizer: Institution|Person|null
 * }
 */
final readonly class AdminEventInputPreparer
{
    public function __construct(
        private AdminEventFormStateBuilder $formState,
        private SpaceEligibilityResolver $spaceEligibilityResolver,
        private SubmissionTimingPolicy $timing,
        private GenerateEventSlugAction $generateEventSlugAction,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return PreparedAdminEventInput
     */
    public function prepare(array $data, Event $event, bool $creating): array
    {
        $recordState = $creating ? [] : $this->formState->formStateForRecord($event);
        $state = $creating
            ? array_replace($this->formState->defaultsForCreate(), $data)
            : array_replace($recordState, $data);

        if (! $creating && array_key_exists('event_date', $data) && ! array_key_exists('end_date', $data)) {
            $state['end_date'] = null;
        }

        $this->validateState($state, $creating ? null : $event);

        [$institutionId, $venueId, $spaceIds] = $this->resolveLocationState($state);

        $organizerId = $this->normalizeOptionalString($state['primary_organizer_id'] ?? $event->primaryOrganizerInvolvement?->involveable_id);
        $primaryOrganizer = OrganizerResolver::find($organizerId);

        return [
            'state' => $state,
            'record_state' => $recordState,
            'institution_id' => $institutionId,
            'venue_id' => $venueId,
            'space_ids' => $spaceIds,
            'organizer_id' => $organizerId,
            'primary_organizer' => $primaryOrganizer,
        ];
    }

    /**
     * @param  PreparedAdminEventInput  $input
     * @return array<string, mixed>
     */
    public function attributes(array $input, Event $event, bool $creating, string $timezone, ?CarbonInterface $startsAt): array
    {
        $state = $input['state'];

        $attributes = [
            'title' => $this->normalizeRequiredString($state['title'] ?? $event->title, 'Event'),
            'description' => array_key_exists('description', $state) ? $state['description'] : $event->description,
            'timezone' => $timezone,
            'gender' => $this->normalizeEnumValue(
                $state['gender'] ?? $event->gender,
                EventGenderRestriction::class,
                EventGenderRestriction::All->value,
            ),
            'age_group' => $this->normalizeEnumValues(
                $state['age_group'] ?? $event->age_group,
                EventAgeGroup::class,
                [EventAgeGroup::AllAges->value],
            ),
            'children_allowed' => array_key_exists('children_allowed', $state)
                ? (bool) $state['children_allowed']
                : (bool) $event->children_allowed,
            'is_muslim_only' => array_key_exists('is_muslim_only', $state)
                ? (bool) $state['is_muslim_only']
                : (bool) $event->is_muslim_only,
            'delivery_mode' => $this->normalizeEnumValue(
                $state['event_format'] ?? $event->delivery_mode,
                EventFormat::class,
                EventFormat::Physical->value,
            ),
            'visibility' => $this->normalizeEnumValue(
                $state['visibility'] ?? $event->visibility,
                EventVisibility::class,
                EventVisibility::Public->value,
            ),
            'event_url' => $this->normalizeOptionalString($state['event_url'] ?? $event->event_url),
            'live_url' => $this->normalizeOptionalString($state['live_url'] ?? $event->live_url),
            'recording_url' => $this->normalizeOptionalString($state['recording_url'] ?? $event->recording_url),
            'institution_id' => $input['institution_id'],
            'default_venue_id' => $input['venue_id'],
            'is_featured' => array_key_exists('is_featured', $state) ? (bool) $state['is_featured'] : (bool) $event->is_featured,
            'status' => $creating ? 'draft' : (string) $event->status,
            'published_at' => $creating ? null : $event->published_at,
        ];

        $attributes['slug'] = $this->generateSlug($attributes, $state, $event, $creating, $startsAt !== null ? (string) $startsAt : null);

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public function requestedStatus(array $state, Event $event, bool $creating): string
    {
        return $this->normalizeEventStatus(
            $state['status'] ?? ($creating ? null : (string) $event->status),
            $creating ? 'draft' : (string) $event->status,
        );
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{0: string|null, 1: string|null, 2: list<string>}
     */
    private function resolveLocationState(array $state): array
    {
        $institutionId = $this->normalizeOptionalString($state['institution_id'] ?? null);
        $venueId = $this->normalizeOptionalString($state['venue_id'] ?? null);
        $spaceIds = $this->normalizeStringArray($state['space_ids'] ?? []);

        if ($venueId !== null) {
            return [null, $venueId, $spaceIds];
        }

        if ($institutionId === null) {
            return [null, null, []];
        }

        return [$institutionId, null, $spaceIds];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function validateState(array $state, ?Event $event = null): void
    {
        $errors = [];
        $primaryOrganizerId = $this->normalizeOptionalString($state['primary_organizer_id'] ?? null);
        $institutionId = $this->normalizeOptionalString($state['institution_id'] ?? null);
        $venueId = $this->normalizeOptionalString($state['venue_id'] ?? null);
        $spaceIds = $this->normalizeStringArray($state['space_ids'] ?? []);
        $personIds = $this->normalizeStringArray($state['persons'] ?? []);

        if ($primaryOrganizerId === null) {
            $errors['primary_organizer_id'][] = __('Penganjur utama diperlukan.');
        } elseif (
            ! Institution::query()->whereKey($primaryOrganizerId)->exists()
            && ! Person::query()->whereKey($primaryOrganizerId)->exists()
        ) {
            $errors['primary_organizer_id'][] = __('Penganjur utama yang dipilih tidak sah.');
        }

        if ($institutionId !== null && $venueId !== null) {
            $message = __('Pilih institusi atau venue, bukan kedua-duanya sekali.');
            $errors['institution_id'][] = $message;
            $errors['venue_id'][] = $message;
        }

        $submissionCountryId = $this->normalizeOptionalString($state['submission_country_id'] ?? null);

        if ($submissionCountryId !== null && $this->timing->resolveSubmissionCountryId($submissionCountryId) === null) {
            $errors['submission_country_id'][] = __('The selected country is invalid.');
        }

        if ($spaceIds !== [] && $institutionId === null && $venueId === null) {
            $errors['space_ids'][] = __('Ruang memerlukan institusi atau venue.');
        }

        if ($spaceIds !== [] && $institutionId !== null) {
            try {
                $this->spaceEligibilityResolver->validateInstitutionSelection($institutionId, $spaceIds);
            } catch (ValidationException $exception) {
                $errors = array_merge_recursive($errors, $exception->errors());
            }
        }

        if ($spaceIds !== [] && $venueId !== null) {
            try {
                $this->spaceEligibilityResolver->validateVenueSelection($venueId, $spaceIds);
            } catch (ValidationException $exception) {
                $errors = array_merge_recursive($errors, $exception->errors());
            }
        }

        $hasOccurrenceSpeaker = $event instanceof Event
            && $event->occurrences()
                ->whereHas('involvements', static fn (Builder $query): Builder => $query
                    ->where('role_code', EventKeyPersonRole::Speaker->value))
                ->exists();

        if ($this->requiresPersons($state['event_category_ids'] ?? []) && $personIds === [] && ! $hasOccurrenceSpeaker) {
            $errors['persons'][] = __('Sekurang-kurangnya seorang penceramah diperlukan untuk jenis majlis ini.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $attributes
     */
    private function generateSlug(array $attributes, array $state, Event $event, bool $creating, ?string $startsAt = null): string
    {
        $primaryOrganizerId = $this->normalizeOptionalString($state['primary_organizer_id'] ?? null);
        $primaryOrganizer = OrganizerResolver::find($primaryOrganizerId);

        $personSlugSegments = $this->generateEventSlugAction->personSlugSegmentsForState(
            $this->normalizeStringArray($state['persons'] ?? []),
            $primaryOrganizer,
        );

        return $this->generateEventSlugAction->handle(
            (string) $attributes['title'],
            $state['event_date'] ?? $startsAt ?? null,
            is_string($attributes['timezone']) ? $attributes['timezone'] : null,
            $creating ? null : (string) $event->getKey(),
            $personSlugSegments,
        );
    }

    private function requiresPersons(mixed $categoryIds): bool
    {
        return app(EventCategoryPolicyResolver::class)->requiresSpeaker(
            app(EventCategoryCatalog::class)->validateTermIds(is_array($categoryIds) ? $categoryIds : [$categoryIds]),
        );
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function normalizeRequiredString(mixed $value, string $fallback): string
    {
        return $this->normalizeOptionalString($value) ?? $fallback;
    }

    /**
     * @param  class-string<BackedEnum>  $enumClass
     */
    private function normalizeEnumValue(mixed $value, string $enumClass, string $default): string
    {
        if ($value instanceof $enumClass) {
            return (string) $value->value;
        }

        if (is_string($value) && $enumClass::tryFrom($value) instanceof BackedEnum) {
            return $value;
        }

        return $default;
    }

    /**
     * @param  class-string<BackedEnum>  $enumClass
     * @param  list<string>  $default
     * @return list<string>
     */
    private function normalizeEnumValues(mixed $values, string $enumClass, array $default = []): array
    {
        if ($values instanceof Collection) {
            $items = $values->all();
        } elseif (is_array($values)) {
            $items = $values;
        } elseif ($values instanceof \Traversable) {
            $items = iterator_to_array($values);
        } else {
            return $default;
        }

        $normalized = Collection::make($items)
            ->map(function (mixed $value) use ($enumClass): ?string {
                if ($value instanceof $enumClass) {
                    return (string) $value->value;
                }

                return is_string($value) && $enumClass::tryFrom($value) instanceof BackedEnum
                    ? $value
                    : null;
            })
            ->filter()
            ->values()
            ->all();

        return $normalized !== [] ? $normalized : $default;
    }

    /**
     * @param  iterable<int, mixed>  $values
     * @return list<string>
     */
    private function normalizeStringArray(iterable $values): array
    {
        return Collection::make($values)
            ->map(function (mixed $value): ?string {
                if (! is_scalar($value)) {
                    return null;
                }

                $trimmed = trim((string) $value);

                return $trimmed !== '' ? $trimmed : null;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeEventStatus(mixed $value, string $fallback): string
    {
        if (! is_scalar($value)) {
            return $fallback;
        }

        $normalized = trim((string) $value);

        return in_array($normalized, ['draft', 'pending', 'approved'], true)
            ? $normalized
            : $fallback;
    }
}
