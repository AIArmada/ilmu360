<?php

namespace App\Actions\Events;

use AIArmada\Addressing\Support\AddressCountryResolver;
use AIArmada\Events\Models\EventTaxonomy;
use AIArmada\Events\Models\EventTerm;
use App\Contracts\EventCategoryCatalog;
use App\Enums\EventAgeGroup;
use App\Enums\EventFormat;
use App\Enums\EventGenderRestriction;
use App\Enums\EventPrayerTime;
use App\Enums\EventTaxonomyCode;
use App\Enums\EventVisibility;
use App\Enums\RegistrationScope;
use App\Enums\TaxonomyTerm\DomainTermCode;
use App\Models\Language;
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
    public function handle(
        User $user,
        ?string $requestedInstitutionId = null,
        ?string $requestedPersonId = null,
    ): array {
        $membershipOptions = $this->resolveAdvancedBuilderMembershipOptionsAction->handle($user);
        $institutionOptions = $membershipOptions['institution_options'];
        $personOptions = $membershipOptions['person_options'];

        $preferredInstitutionId = is_string($requestedInstitutionId)
            && $requestedInstitutionId !== ''
            && array_key_exists($requestedInstitutionId, $institutionOptions)
                ? $requestedInstitutionId
                : null;

        $preferredPersonId = is_string($requestedPersonId)
            && $requestedPersonId !== ''
            && array_key_exists($requestedPersonId, $personOptions)
                ? $requestedPersonId
                : null;

        $defaultPrimaryOrganizerId = $preferredInstitutionId
            ?: $preferredPersonId
            ?: array_key_first($institutionOptions)
            ?: array_key_first($personOptions);
        $defaultPrimaryOrganizerIsInstitution = is_string($defaultPrimaryOrganizerId)
            && array_key_exists($defaultPrimaryOrganizerId, $institutionOptions);

        $programStartsAt = now('Asia/Kuala_Lumpur')->addDays(2)->setTime(20, 0);
        $programEndsAt = now('Asia/Kuala_Lumpur')->addDays(30)->setTime(22, 0);
        $categoryIds = array_slice(array_keys($this->categoryCatalog->options()), 0, 1);
        $domainId = EventTerm::query()
            ->where('event_taxonomy_id', EventTaxonomy::query()->where('code', EventTaxonomyCode::Domain->value)->value('id'))
            ->where('code', DomainTermCode::AgamaKerohanian->value)
            ->value('id');
        $malayId = Language::query()->where('code', 'ms')->value('id');

        return [
            'institution_options' => $institutionOptions,
            'person_options' => $personOptions,
            'default_form' => [
                'title' => '',
                'description' => '',
                'timezone' => 'Asia/Kuala_Lumpur',
                'program_starts_at' => $programStartsAt->format('Y-m-d\TH:i'),
                'program_ends_at' => $programEndsAt->format('Y-m-d\TH:i'),
                'event_date' => $programStartsAt->toDateString(),
                'prayer_time' => EventPrayerTime::LainWaktu->value,
                'custom_time' => $programStartsAt->format('H:i'),
                'end_time' => $programEndsAt->format('H:i'),
                'submission_country_id' => app(AddressCountryResolver::class)->resolveId('MY'),
                'primary_organizer_id' => $defaultPrimaryOrganizerId,
                'primary_organizer_kind' => $defaultPrimaryOrganizerIsInstitution ? 'institution' : 'person',
                'location_institution_id' => $defaultPrimaryOrganizerIsInstitution
                    ? $defaultPrimaryOrganizerId
                    : null,
                'location_same_as_institution' => $defaultPrimaryOrganizerIsInstitution,
                'location_type' => 'institution',
                'location_venue_id' => null,
                'space_ids' => [],
                'default_event_category_ids' => $categoryIds,
                'event_category_ids' => $categoryIds,
                'default_event_format' => EventFormat::Physical->value,
                'event_format' => EventFormat::Physical->value,
                'visibility' => EventVisibility::Public->value,
                'domain_tags' => $domainId !== null ? (string) $domainId : null,
                'discipline_tags' => [],
                'source_tags' => [],
                'issue_tags' => [],
                'references' => [],
                'event_url' => null,
                'live_url' => null,
                'gender' => EventGenderRestriction::All->value,
                'age_group' => [EventAgeGroup::AllAges->value],
                'children_allowed' => true,
                'is_muslim_only' => false,
                'languages' => $malayId !== null ? [(string) $malayId] : [],
                'persons' => $preferredPersonId !== null ? [$preferredPersonId] : [],
                'other_key_people' => [],
                'registration_required' => false,
                'registration_mode' => RegistrationScope::Event->value,
            ],
        ];
    }
}
