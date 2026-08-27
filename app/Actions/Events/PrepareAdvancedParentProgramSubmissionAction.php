<?php

namespace App\Actions\Events;

use App\Enums\EventPrayerTime;
use App\Models\Institution;
use App\Models\Person;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

class PrepareAdvancedParentProgramSubmissionAction
{
    use AsAction;

    public function __construct(
        protected ResolveAdvancedBuilderMembershipOptionsAction $resolveAdvancedBuilderMembershipOptionsAction,
    ) {}

    /**
     * @param  array<string, mixed>  $form
     * @return array{
     *     timezone: string,
     *     primary_organizer: Institution|Person,
     *     location_institution_id: ?string,
     *     program_starts_at: Carbon,
     *     program_ends_at: Carbon
     * }
     */
    public function handle(
        User $user,
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
        $primaryOrganizer = $this->resolvePrimaryOrganizer($user, (string) $form['primary_organizer_id']);
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
            'primary_organizer' => $primaryOrganizer,
            'location_institution_id' => (string) ($form['location_type'] ?? 'institution') === 'venue'
                ? null
                : $this->resolveLocationInstitutionId(
                    $user,
                    $primaryOrganizer,
                    $form['location_institution_id'] ?? null,
                ),
            'program_starts_at' => $programStartsAt,
            'program_ends_at' => $programEndsAt,
        ];
    }

    protected function resolvePrimaryOrganizer(User $user, string $primaryOrganizerId): Institution|Person
    {
        $membershipOptions = $this->resolveAdvancedBuilderMembershipOptionsAction->handle($user);

        if (array_key_exists($primaryOrganizerId, $membershipOptions['institution_options'])) {
            $institution = Institution::query()->find($primaryOrganizerId);

            if ($institution instanceof Institution) {
                return $institution;
            }
        }

        if (array_key_exists($primaryOrganizerId, $membershipOptions['person_options'])) {
            $person = Person::query()->find($primaryOrganizerId);

            if ($person instanceof Person) {
                return $person;
            }
        }

        abort(403);
    }

    protected function resolveLocationInstitutionId(User $user, Institution|Person $primaryOrganizer, mixed $locationInstitutionId): ?string
    {
        if ($primaryOrganizer instanceof Institution) {
            return (string) $primaryOrganizer->getKey();
        }

        if (! is_string($locationInstitutionId) || $locationInstitutionId === '') {
            return null;
        }

        $membershipOptions = $this->resolveAdvancedBuilderMembershipOptionsAction->handle($user);

        if (! array_key_exists($locationInstitutionId, $membershipOptions['institution_options'])) {
            abort(403);
        }

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
        return [
            EventPrayerTime::SelepasSubuh->value => '06:30',
            EventPrayerTime::SelepasZuhur->value => '13:30',
            EventPrayerTime::SebelumJumaat->value => '13:45',
            EventPrayerTime::SelepasJumaat->value => '14:00',
            EventPrayerTime::SelepasAsar->value => '17:00',
            EventPrayerTime::SebelumMaghrib->value => '19:45',
            EventPrayerTime::SelepasMaghrib->value => '20:00',
            EventPrayerTime::SelepasIsyak->value => '21:30',
            EventPrayerTime::SelepasTarawih->value => '22:30',
        ];
    }
}
