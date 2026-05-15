<?php

namespace App\Actions\Events;

use App\Enums\EventFormat;
use App\Enums\EventType;
use App\Enums\EventVisibility;
use App\Enums\RegistrationMode;
use App\Models\User;
use Lorisleiva\Actions\Concerns\AsAction;

class ResolveAdvancedBuilderContextAction
{
    use AsAction;

    public function __construct(
        protected ResolveAdvancedBuilderMembershipOptionsAction $resolveAdvancedBuilderMembershipOptionsAction,
    ) {}

    /**
     * @return array{
     *     institution_options: array<string, string>,
     *     speaker_options: array<string, string>,
     *     default_form: array<string, mixed>
     * }
     */
    public function handle(User $user, ?string $requestedInstitutionId = null): array
    {
        $membershipOptions = $this->resolveAdvancedBuilderMembershipOptionsAction->handle($user);
        $institutionOptions = $membershipOptions['institution_options'];
        $speakerOptions = $membershipOptions['speaker_options'];

        $preferredInstitutionId = is_string($requestedInstitutionId)
            && $requestedInstitutionId !== ''
            && array_key_exists($requestedInstitutionId, $institutionOptions)
                ? $requestedInstitutionId
                : null;

        $defaultOrganizerType = $institutionOptions !== [] ? 'institution' : 'speaker';
        $defaultOrganizerId = $defaultOrganizerType === 'institution'
            ? $preferredInstitutionId ?: array_key_first($institutionOptions)
            : array_key_first($speakerOptions);

        return [
            'institution_options' => $institutionOptions,
            'speaker_options' => $speakerOptions,
            'default_form' => [
                'title' => '',
                'description' => '',
                'timezone' => 'Asia/Kuala_Lumpur',
                'program_starts_at' => now('Asia/Kuala_Lumpur')->addDays(2)->setTime(20, 0)->format('Y-m-d\TH:i'),
                'program_ends_at' => now('Asia/Kuala_Lumpur')->addDays(30)->setTime(22, 0)->format('Y-m-d\TH:i'),
                'organizer_type' => $defaultOrganizerType,
                'organizer_id' => $defaultOrganizerId,
                'location_institution_id' => $defaultOrganizerType === 'institution'
                    ? $defaultOrganizerId
                    : ($preferredInstitutionId ?: array_key_first($institutionOptions)),
                'default_event_type' => EventType::KuliahCeramah->value,
                'default_event_format' => EventFormat::Physical->value,
                'visibility' => EventVisibility::Public->value,
                'registration_required' => false,
                'registration_mode' => RegistrationMode::Event->value,
            ],
        ];
    }
}
