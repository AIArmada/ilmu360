<?php

namespace App\Actions\Events;

use App\Models\Institution;
use App\Models\Speaker;
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
     *     primary_organizer: Institution|Speaker,
     *     location_institution_id: ?string,
     *     program_starts_at: Carbon,
     *     program_ends_at: Carbon
     * }
     */
    public function handle(User $user, array $form): array
    {
        $timezone = (string) $form['timezone'];
        $primaryOrganizer = $this->resolvePrimaryOrganizer($user, (string) $form['primary_organizer_id']);
        $programStartsAt = Carbon::parse((string) $form['program_starts_at'], $timezone)->utc();
        $programEndsAt = Carbon::parse((string) $form['program_ends_at'], $timezone)->utc();

        if ($programEndsAt->lessThanOrEqualTo($programStartsAt)) {
            throw ValidationException::withMessages([
                'form.program_ends_at' => __('The program end must be after the program start.'),
            ]);
        }

        return [
            'timezone' => $timezone,
            'primary_organizer' => $primaryOrganizer,
            'location_institution_id' => $this->resolveLocationInstitutionId(
                $user,
                $primaryOrganizer,
                $form['location_institution_id'] ?? null,
            ),
            'program_starts_at' => $programStartsAt,
            'program_ends_at' => $programEndsAt,
        ];
    }

    protected function resolvePrimaryOrganizer(User $user, string $primaryOrganizerId): Institution|Speaker
    {
        $membershipOptions = $this->resolveAdvancedBuilderMembershipOptionsAction->handle($user);

        if (array_key_exists($primaryOrganizerId, $membershipOptions['institution_options'])) {
            $institution = Institution::query()->find($primaryOrganizerId);

            if ($institution instanceof Institution) {
                return $institution;
            }
        }

        if (array_key_exists($primaryOrganizerId, $membershipOptions['speaker_options'])) {
            $speaker = Speaker::query()->find($primaryOrganizerId);

            if ($speaker instanceof Speaker) {
                return $speaker;
            }
        }

        abort(403);
    }

    protected function resolveLocationInstitutionId(User $user, Institution|Speaker $primaryOrganizer, mixed $locationInstitutionId): ?string
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
}
