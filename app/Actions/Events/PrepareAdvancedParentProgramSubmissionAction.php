<?php

namespace App\Actions\Events;

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
     *     organizer_type: string,
     *     organizer_id: string,
     *     location_institution_id: ?string,
     *     program_starts_at: Carbon,
     *     program_ends_at: Carbon
     * }
     */
    public function handle(User $user, array $form): array
    {
        $timezone = (string) $form['timezone'];
        $organizerType = (string) $form['organizer_type'];
        $organizerId = (string) $form['organizer_id'];
        $programStartsAt = Carbon::parse((string) $form['program_starts_at'], $timezone)->utc();
        $programEndsAt = Carbon::parse((string) $form['program_ends_at'], $timezone)->utc();

        $this->ensureOrganizerIsMemberOwned($user, $organizerType, $organizerId);

        if ($programEndsAt->lessThanOrEqualTo($programStartsAt)) {
            throw ValidationException::withMessages([
                'form.program_ends_at' => __('The program end must be after the program start.'),
            ]);
        }

        return [
            'timezone' => $timezone,
            'organizer_type' => $organizerType,
            'organizer_id' => $organizerId,
            'location_institution_id' => $this->resolveLocationInstitutionId(
                $user,
                $organizerType,
                $organizerId,
                $form['location_institution_id'] ?? null,
            ),
            'program_starts_at' => $programStartsAt,
            'program_ends_at' => $programEndsAt,
        ];
    }

    protected function ensureOrganizerIsMemberOwned(User $user, string $organizerType, string $organizerId): void
    {
        $membershipOptions = $this->resolveAdvancedBuilderMembershipOptionsAction->handle($user);

        $allowed = match ($organizerType) {
            'institution' => array_key_exists($organizerId, $membershipOptions['institution_options']),
            'speaker' => array_key_exists($organizerId, $membershipOptions['speaker_options']),
            default => false,
        };

        if (! $allowed) {
            abort(403);
        }
    }

    protected function resolveLocationInstitutionId(User $user, string $organizerType, string $organizerId, mixed $locationInstitutionId): ?string
    {
        if ($organizerType === 'institution') {
            return $organizerId;
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
