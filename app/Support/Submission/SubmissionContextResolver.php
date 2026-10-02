<?php

declare(strict_types=1);

namespace App\Support\Submission;

use AIArmada\Events\Models\EventOccurrence;
use App\Contracts\SpaceEligibilityResolver;
use App\Enums\EventFormat;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\User;
use App\Support\Events\OrganizerResolver;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Fail-closed context resolution for the event-submission pipeline.
 *
 * Owns scoped-institution/container authorization, scoped state
 * normalization, and model/country/target/occurrence resolution. The
 * orchestrator calls these in phase order; this class never writes.
 */
final class SubmissionContextResolver
{
    public function __construct(
        private readonly SubmissionTimingPolicy $timing,
        private readonly SpaceEligibilityResolver $spaceEligibilityResolver,
        private readonly EntitySubmissionAccess $entitySubmissionAccess,
    ) {}

    /**
     * Re-resolve the scoped institution from storage and re-check current
     * membership. The incoming model is never trusted: a deleted institution,
     * a revoked membership, or a non-UUID key fails closed instead of
     * auto-approving. A non-null requested scope in state that does not match
     * the authorized model also fails closed instead of downgrading to the
     * public flow.
     *
     * @param  array<string, mixed>  $state
     */
    public function authorizedScopedInstitution(
        ?Institution $scopedInstitution,
        ?User $submitter,
        string $validationKeyPrefix,
        array $state = [],
    ): ?Institution {
        $requestedScopeId = $state['scoped_institution_id'] ?? null;
        $requestedScopeId = is_string($requestedScopeId) && trim($requestedScopeId) !== ''
            ? trim($requestedScopeId)
            : null;

        if (! $scopedInstitution instanceof Institution) {
            if ($requestedScopeId !== null) {
                throw ValidationException::withMessages([
                    SubmissionValues::prefixedKey('scoped_institution_id', $validationKeyPrefix) => __('Anda tidak dibenarkan menghantar bagi pihak institusi ini.'),
                ]);
            }

            return null;
        }

        $institutionId = (string) $scopedInstitution->getKey();

        if (! Str::isUuid($institutionId)) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('scoped_institution_id', $validationKeyPrefix) => __('Anda tidak dibenarkan menghantar bagi pihak institusi ini.'),
            ]);
        }

        if ($requestedScopeId !== null && $requestedScopeId !== $institutionId) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('scoped_institution_id', $validationKeyPrefix) => __('Anda tidak dibenarkan menghantar bagi pihak institusi ini.'),
            ]);
        }

        $persisted = $submitter instanceof User
            ? $this->entitySubmissionAccess->memberInstitutionQueryForSubmitter($submitter)
                ->whereKey($institutionId)
                ->first()
            : null;

        if ($persisted instanceof Institution) {
            return $persisted;
        }

        throw ValidationException::withMessages([
            SubmissionValues::prefixedKey('scoped_institution_id', $validationKeyPrefix) => __('Anda tidak dibenarkan menghantar bagi pihak institusi ini.'),
        ]);
    }

    /**
     * Re-resolve the container from storage, require update authorization,
     * and — under an institution scope — require actual primary-organizer
     * ownership. The location institution is not ownership.
     */
    public function authorizedEventContainer(
        ?Event $eventContainer,
        ?User $submitter,
        ?Institution $scopedInstitution,
        string $validationKeyPrefix,
    ): ?Event {
        if (! $eventContainer instanceof Event) {
            return null;
        }

        $containerId = (string) $eventContainer->getKey();

        if (! Str::isUuid($containerId)) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('event_id', $validationKeyPrefix) => __('Majlis yang dipilih tidak lagi tersedia.'),
            ]);
        }

        $persisted = Event::query()->find($containerId);

        if (! $persisted instanceof Event) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('event_id', $validationKeyPrefix) => __('Majlis yang dipilih tidak lagi tersedia.'),
            ]);
        }

        if (! $submitter instanceof User || ! $submitter->can('update', $persisted)) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('event_id', $validationKeyPrefix) => __('Anda tidak dibenarkan mengubah majlis ini.'),
            ]);
        }

        if (
            $scopedInstitution instanceof Institution
            && ! OrganizerResolver::involvementMatchesInstitution(
                $persisted->primaryOrganizerInvolvement,
                $scopedInstitution,
            )
        ) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('event_id', $validationKeyPrefix) => __('Majlis yang dipilih bukan di bawah institusi ini.'),
            ]);
        }

        return $persisted;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function normalizeScopedInstitutionState(array $validated, ?Institution $scopedInstitution, string $validationKeyPrefix): array
    {
        if (! $scopedInstitution instanceof Institution) {
            return $validated;
        }

        $validated['primary_organizer_id'] = $scopedInstitution->getKey();
        $validated['primary_organizer_kind'] = 'institution';
        $validated['primary_organizer_institution_id'] = $scopedInstitution->getKey();
        $validated['location_same_as_institution'] = (bool) ($validated['location_same_as_institution'] ?? true);

        if (SubmissionValues::enumValue($validated['event_format'] ?? null, EventFormat::Physical->value) === EventFormat::Online->value) {
            $validated['location_type'] = 'institution';
            $validated['location_institution_id'] = $scopedInstitution->getKey();
            $validated['location_venue_id'] = null;

            return $validated;
        }

        if ($validated['location_same_as_institution']) {
            $validated['location_type'] = 'institution';
            $validated['location_institution_id'] = $scopedInstitution->getKey();
            $validated['location_venue_id'] = null;

            return $validated;
        }

        $validated['location_type'] = 'venue';
        $validated['location_institution_id'] = null;

        if (! filled($validated['location_venue_id'] ?? null)) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('location_venue_id', $validationKeyPrefix) => __('Sila pilih lokasi untuk majlis ini.'),
            ]);
        }

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function normalizeSpaceSelection(array $validated): array
    {
        $spaceIds = [];

        if (is_array($validated['space_ids'] ?? null)) {
            foreach ($validated['space_ids'] as $value) {
                if (is_string($value) && trim($value) !== '') {
                    $spaceIds[] = trim($value);
                }
            }
        }

        $spaceId = $this->normalizeOptionalString($validated['space_id'] ?? null);

        if ($spaceId !== null) {
            $spaceIds[] = $spaceId;
        }

        $validated['space_ids'] = array_values(array_unique($spaceIds));

        return $validated;
    }

    /** @param  array{submission_country_id?: string|null}  $validated */
    public function resolveSubmissionCountryId(array $validated, string $validationKeyPrefix): string
    {
        $value = $validated['submission_country_id'] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('submission_country_id', $validationKeyPrefix) => __('The submission country is required.'),
            ]);
        }

        $resolvedCountryId = $this->timing->resolveSubmissionCountryId($value);

        if (! is_string($resolvedCountryId)) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('submission_country_id', $validationKeyPrefix) => __('The selected country is invalid.'),
            ]);
        }

        return $resolvedCountryId;
    }

    public function resolvePrimaryOrganizer(mixed $primaryOrganizerId): Institution|Person|null
    {
        $organizerId = is_string($primaryOrganizerId) ? trim($primaryOrganizerId) : '';

        if ($organizerId === '') {
            return null;
        }

        return OrganizerResolver::find($organizerId);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function assertConditionalRequirements(array $validated, ?string $organizerKind, string $validationKeyPrefix): void
    {
        $eventFormat = SubmissionValues::enumValue($validated['event_format'] ?? null, EventFormat::Physical->value);
        $sameAsInstitution = (bool) ($validated['location_same_as_institution'] ?? true);
        $locationType = (string) ($validated['location_type'] ?? '');

        if ($organizerKind === null) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('primary_organizer_id', $validationKeyPrefix) => __('Sila pilih penganjur utama.'),
            ]);
        }

        if ($eventFormat === EventFormat::Online->value) {
            return;
        }

        $requiresLocationChoice = $organizerKind === 'person' || ! $sameAsInstitution;

        if (! $requiresLocationChoice) {
            return;
        }

        if (! in_array($locationType, ['institution', 'venue'], true)) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('location_type', $validationKeyPrefix) => __('Sila pilih jenis lokasi untuk majlis ini.'),
            ]);
        }

        if ($locationType === 'institution' && ! filled($validated['location_institution_id'] ?? null)) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('location_institution_id', $validationKeyPrefix) => __('Sila pilih institusi lokasi untuk majlis ini.'),
            ]);
        }

        if ($locationType === 'venue' && ! filled($validated['location_venue_id'] ?? null)) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('location_venue_id', $validationKeyPrefix) => __('Sila pilih lokasi untuk majlis ini.'),
            ]);
        }
    }

    /**
     * Validate space ownership before any write or captcha check. The shared
     * resolver throws unprefixed keys, so remap them to this form's prefix.
     *
     * @param  array<string, mixed>  $validated
     */
    public function assertSpaceSelectionIsEligible(
        array $validated,
        ?string $targetInstitutionId,
        ?string $targetVenueId,
        string $validationKeyPrefix,
    ): void {
        $spaceIds = is_array($validated['space_ids'] ?? null) ? $validated['space_ids'] : [];

        if ($spaceIds === []) {
            return;
        }

        if (SubmissionValues::enumValue($validated['event_format'] ?? null) === EventFormat::Online->value
            || ($targetInstitutionId === null && $targetVenueId === null)) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('space_ids', $validationKeyPrefix) => __('Pilihan tidak sah.'),
            ]);
        }

        try {
            if ($targetVenueId !== null) {
                $this->spaceEligibilityResolver->validateVenueSelection($targetVenueId, $spaceIds);
            }

            if ($targetInstitutionId !== null) {
                $this->spaceEligibilityResolver->validateInstitutionSelection($targetInstitutionId, $spaceIds);
            }
        } catch (ValidationException $exception) {
            $messages = [];

            foreach ($exception->errors() as $messagesForField) {
                foreach ((array) $messagesForField as $message) {
                    $messages[SubmissionValues::prefixedKey('space_ids', $validationKeyPrefix)][] = $message;
                }
            }

            throw ValidationException::withMessages($messages);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: string|null, 1: string|null}
     */
    public function resolveTargetLocation(
        array $validated,
        Institution|Person $primaryOrganizer,
    ): array {
        if (SubmissionValues::enumValue($validated['event_format'] ?? null) === EventFormat::Online->value) {
            return [$primaryOrganizer instanceof Institution ? (string) $primaryOrganizer->getKey() : null, null];
        }

        $targetInstitutionId = null;
        $targetVenueId = null;
        $locationType = $validated['location_type'] ?? 'institution';
        $locationInstitutionId = $this->normalizeId($validated['location_institution_id'] ?? null);
        $venueId = $this->normalizeId($validated['location_venue_id'] ?? null);

        if ($locationType === 'institution' && $locationInstitutionId !== null) {
            $venueId = null;
        } elseif ($locationType === 'venue' && $venueId !== null) {
            $locationInstitutionId = null;
        }

        if ($primaryOrganizer instanceof Institution) {
            if (($validated['location_same_as_institution'] ?? true) == true) {
                $targetInstitutionId = (string) $primaryOrganizer->getKey();
            } elseif (($validated['location_type'] ?? null) === 'institution') {
                $targetInstitutionId = $locationInstitutionId;
            } else {
                $targetVenueId = $venueId;
            }
        } elseif ($locationInstitutionId !== null) {
            $targetInstitutionId = $locationInstitutionId;
        } elseif ($venueId !== null) {
            $targetVenueId = $venueId;
        }

        return [$targetInstitutionId, $targetVenueId];
    }

    /**
     * Resolve and validate the session occurrence before captcha. An explicit
     * selection must belong to the container and be eligible. An omitted
     * selection resolves the single eligible default; multi-occurrence
     * containers require explicit selection instead of silently using the
     * primary, and a terminal primary never passes through.
     *
     * @param  array<string, mixed>  $validated
     */
    public function resolveSelectedOccurrenceId(
        array $validated,
        ?Event $eventContainer,
        string $validationKeyPrefix,
    ): ?string {
        $raw = $validated['event_occurrence_id'] ?? null;
        $hasSelection = ! ($raw === null || (is_string($raw) && trim($raw) === ''));

        if (! $eventContainer instanceof Event) {
            if ($hasSelection) {
                throw ValidationException::withMessages([
                    SubmissionValues::prefixedKey('event_occurrence_id', $validationKeyPrefix) => __('Sesi hanya boleh ditambah kepada majlis sedia ada.'),
                ]);
            }

            return null;
        }

        if ($hasSelection) {
            if (! is_string($raw) || ! Str::isUuid(trim($raw))) {
                throw ValidationException::withMessages([
                    SubmissionValues::prefixedKey('event_occurrence_id', $validationKeyPrefix) => __('Sila pilih jadual majlis yang sah.'),
                ]);
            }

            $occurrence = EventOccurrence::query()
                ->whereKey(trim($raw))
                ->where('event_id', $eventContainer->getKey())
                ->first();

            if (! $occurrence instanceof EventOccurrence) {
                throw ValidationException::withMessages([
                    SubmissionValues::prefixedKey('event_occurrence_id', $validationKeyPrefix) => __('Jadual yang dipilih bukan milik majlis ini.'),
                ]);
            }

            if ($this->isTerminalOccurrence($occurrence)) {
                throw ValidationException::withMessages([
                    SubmissionValues::prefixedKey('event_occurrence_id', $validationKeyPrefix) => __('Jadual yang dipilih tidak lagi menerima sesi baharu.'),
                ]);
            }

            return (string) $occurrence->getKey();
        }

        $eligible = EventOccurrence::query()
            ->where('event_id', $eventContainer->getKey())
            ->whereNotIn('status', [EventOccurrence::CANCELLED, EventOccurrence::COMPLETED, EventOccurrence::ARCHIVED])
            ->orderBy('starts_at')
            ->get(['id']);

        if ($eligible->isEmpty()) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('event_occurrence_id', $validationKeyPrefix) => __('Jadual yang dipilih tidak lagi menerima sesi baharu.'),
            ]);
        }

        if ($eligible->count() > 1) {
            throw ValidationException::withMessages([
                SubmissionValues::prefixedKey('event_occurrence_id', $validationKeyPrefix) => __('Sila pilih jadual majlis untuk sesi ini.'),
            ]);
        }

        return (string) $eligible->first()->getKey();
    }

    private function isTerminalOccurrence(EventOccurrence $occurrence): bool
    {
        return in_array((string) $occurrence->status, [EventOccurrence::CANCELLED, EventOccurrence::COMPLETED, EventOccurrence::ARCHIVED], true);
    }

    private function normalizeId(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
