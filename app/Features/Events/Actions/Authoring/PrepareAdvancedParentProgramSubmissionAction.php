<?php

namespace App\Features\Events\Actions\Authoring;

use App\Features\Events\Api\AdvancedBuilderMembershipOptions;
use App\Features\Events\Api\AdvancedProgramPreparation;
use App\Features\Prayer\Api\EventPrayerTime;
use App\Features\Prayer\Api\HardcodedPrayerFallback;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

final readonly class PrepareAdvancedParentProgramSubmissionAction implements AdvancedProgramPreparation
{
    public function __construct(
        private AdvancedBuilderMembershipOptions $advancedBuilderMembershipOptions,
    ) {}

    /**
     * @param  array<string, mixed>  $form
     * @return array{
     *     timezone: string,
     *     primary_organizer_type: string,
     *     primary_organizer_id: string,
     *     location_institution_id: ?string,
     *     program_starts_at: string,
     *     program_ends_at: string
     * }
     */
    public function prepare(
        string $accountId,
        array $form,
        ?string $lockedInstitutionId = null,
        ?string $lockedPersonId = null,
    ): array {
        if (filled($lockedInstitutionId)) {
            $form['primary_organizer_id'] = $lockedInstitutionId;
            $form['location_institution_id'] = $lockedInstitutionId;
        } elseif (filled($lockedPersonId)) {
            $form['primary_organizer_id'] = $lockedPersonId;
        }

        $timezone = (string) $form['timezone'];
        $primaryOrganizer = $this->resolvePrimaryOrganizer($accountId, (string) $form['primary_organizer_id']);
        $programStartsAt = Carbon::parse((string) $form['program_starts_at'], $timezone)->utc();
        $programEndsAt = Carbon::parse((string) $form['program_ends_at'], $timezone)->utc();

        if ($programEndsAt->lessThanOrEqualTo($programStartsAt)) {
            throw ValidationException::withMessages([
                'form.program_ends_at' => __('The program end must be after the program start.'),
            ]);
        }

        $firstSessionStartsAt = $this->resolveFirstSessionStartsAt($form, $timezone);

        if (
            $firstSessionStartsAt instanceof Carbon
            && (
                $firstSessionStartsAt->lessThan($programStartsAt)
                || $firstSessionStartsAt->greaterThan($programEndsAt)
            )
        ) {
            throw ValidationException::withMessages([
                'form.event_date' => __('The first session must fall within the program timeframe.'),
            ]);
        }

        return [
            'timezone' => $timezone,
            'primary_organizer_type' => $primaryOrganizer['type'],
            'primary_organizer_id' => $primaryOrganizer['id'],
            'location_institution_id' => (string) ($form['location_type'] ?? 'institution') === 'venue'
                ? null
                : $this->resolveLocationInstitutionId(
                    $accountId,
                    $primaryOrganizer['type'],
                    $primaryOrganizer['id'],
                    $form['location_institution_id'] ?? null,
                ),
            'program_starts_at' => $programStartsAt->toIso8601String(),
            'program_ends_at' => $programEndsAt->toIso8601String(),
        ];
    }

    /** @return array{type: 'institution'|'person', id: string} */
    private function resolvePrimaryOrganizer(string $accountId, string $primaryOrganizerId): array
    {
        $options = $this->advancedBuilderMembershipOptions->forAccount($accountId);

        if (array_key_exists($primaryOrganizerId, $options['institution_options'])) {
            return ['type' => 'institution', 'id' => $primaryOrganizerId];
        }

        if (array_key_exists($primaryOrganizerId, $options['person_options'])) {
            return ['type' => 'person', 'id' => $primaryOrganizerId];
        }

        abort(403);
    }

    private function resolveLocationInstitutionId(string $accountId, string $primaryOrganizerType, string $primaryOrganizerId, mixed $locationInstitutionId): ?string
    {
        if ($primaryOrganizerType === 'institution') {
            return $primaryOrganizerId;
        }

        if (! is_string($locationInstitutionId) || $locationInstitutionId === '') {
            return null;
        }

        $options = $this->advancedBuilderMembershipOptions->forAccount($accountId);

        abort_unless(array_key_exists($locationInstitutionId, $options['institution_options']), 403);

        return $locationInstitutionId;
    }

    /**
     * @param  array<string, mixed>  $form
     */
    protected function resolveFirstSessionStartsAt(array $form, string $timezone): ?Carbon
    {
        $eventDate = $form['event_date'] ?? null;

        if (! is_string($eventDate) || $eventDate === '') {
            return null;
        }

        $date = Carbon::parse($eventDate, $timezone)->startOfDay();
        $prayerTime = $form['prayer_time'] ?? null;
        $prayerTime = $prayerTime instanceof EventPrayerTime
            ? $prayerTime
            : EventPrayerTime::tryFrom((string) $prayerTime);
        $time = $prayerTime === EventPrayerTime::LainWaktu
            ? ($form['custom_time'] ?? null)
            : $this->defaultPrayerTimes()[$prayerTime instanceof EventPrayerTime ? $prayerTime->value : ''] ?? null;

        if (! is_string($time) || $time === '') {
            return null;
        }

        return $date->setTimeFromTimeString($time);
    }

    /** @return array<string, string> */
    protected function defaultPrayerTimes(): array
    {
        return HardcodedPrayerFallback::MAP;
    }
}
