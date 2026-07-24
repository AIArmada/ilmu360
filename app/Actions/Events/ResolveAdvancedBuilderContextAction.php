<?php

namespace App\Actions\Events;

use App\Contracts\EventCategoryCatalog;
use App\Enums\EventFormat;
use App\Enums\EventVisibility;
use App\Enums\RegistrationScope;
use App\Models\User;
use Lorisleiva\Actions\Concerns\AsAction;

class ResolveAdvancedBuilderContextAction
{
    use AsAction;

    public function __construct(
        protected ResolveAdvancedBuilderMembershipOptionsAction $resolveAdvancedBuilderMembershipOptionsAction,
        protected EventCategoryCatalog $categoryCatalog,
    ) {}

    /**
     * @return array{
     *     institution_options: array<string, string>,
     *     person_options: array<string, string>,
     *     default_form: array<string, mixed>
     * }
     */
    public function handle(User $user, ?string $requestedInstitutionId = null): array
    {
        $membershipOptions = $this->resolveAdvancedBuilderMembershipOptionsAction->handle($user);
        $institutionOptions = $membershipOptions['institution_options'];
        $personOptions = $membershipOptions['person_options'];

        $preferredInstitutionId = is_string($requestedInstitutionId)
            && $requestedInstitutionId !== ''
            && array_key_exists($requestedInstitutionId, $institutionOptions)
                ? $requestedInstitutionId
                : null;

        $defaultPrimaryOrganizerId = $preferredInstitutionId
            ?: array_key_first($institutionOptions)
            ?: array_key_first($personOptions);
        $defaultPrimaryOrganizerIsInstitution = is_string($defaultPrimaryOrganizerId)
            && array_key_exists($defaultPrimaryOrganizerId, $institutionOptions);

        return [
            'institution_options' => $institutionOptions,
            'person_options' => $personOptions,
            'default_form' => [
                'title' => '',
                'description' => '',
                'timezone' => 'Asia/Kuala_Lumpur',
                'program_starts_at' => now('Asia/Kuala_Lumpur')->addDays(2)->setTime(20, 0)->format('Y-m-d\TH:i'),
                'program_ends_at' => now('Asia/Kuala_Lumpur')->addDays(30)->setTime(22, 0)->format('Y-m-d\TH:i'),
                'primary_organizer_id' => $defaultPrimaryOrganizerId,
                'location_institution_id' => $defaultPrimaryOrganizerIsInstitution
                    ? $defaultPrimaryOrganizerId
                    : ($preferredInstitutionId ?: array_key_first($institutionOptions)),
                'default_event_category_ids' => array_slice(array_keys($this->categoryCatalog->options()), 0, 1),
                'default_event_format' => EventFormat::Physical->value,
                'visibility' => EventVisibility::Public->value,
                'registration_required' => false,
                'registration_mode' => RegistrationScope::Event->value,
            ],
        ];
    }
}
