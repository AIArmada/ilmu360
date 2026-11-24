<?php

declare(strict_types=1);

namespace App\Features\Discovery\Services;

use App\Features\Contributions\Api\SubmissionCatalog;
use App\Features\DonationChannels\Api\DonationChannelCountryProfiles;
use App\Features\Events\Api\AdvancedBuilderContext;
use App\Features\Events\Api\TaxonomyCatalog;
use App\Features\Institutions\Api\InstitutionCatalog;
use App\Features\Membership\Api\MembershipSubjectOptionsCatalog;
use App\Features\Membership\Api\MemberSubjectType;
use App\Features\Places\Api\PlaceCatalog;
use App\Features\Prayer\Api\PrayerInstitutionCatalog;
use App\Features\References\Api\ReferenceCatalog;
use App\Models\User;
use App\Platform\Language\Api\LanguageOptions;
use App\Platform\Location\Api\AddressCatalog;

final readonly class CatalogDispatcher
{
    public function __construct(
        private AddressCatalog $addresses,
        private LanguageOptions $languages,
        private TaxonomyCatalog $taxonomy,
        private ReferenceCatalog $references,
        private SubmissionCatalog $submissions,
        private PlaceCatalog $places,
        private MembershipSubjectOptionsCatalog $membershipSubjects,
        private PrayerInstitutionCatalog $prayerInstitutions,
        private InstitutionCatalog $institutions,
        private AdvancedBuilderContext $advancedBuilder,
        private DonationChannelCountryProfiles $donationProfiles,
    ) {}

    /**
     * @return list<array{code: string, name: string}>
     */
    public function donationBanks(?string $countryId): array
    {
        return collect($this->donationProfiles->forCountryId($countryId)->banks->options())
            ->map(static fn (string $name, int|string $code): array => ['code' => (string) $code, 'name' => $name])
            ->values()
            ->all();
    }

    /**
     * @return array{country_code: string|null, covered: bool, supports_routing_codes: bool}
     */
    public function donationBanksMeta(?string $countryId): array
    {
        $profile = $this->donationProfiles->forCountryId($countryId);

        return [
            'country_code' => $profile->country_code,
            'covered' => $profile->banks->covers(),
            'supports_routing_codes' => $profile->supports_routing_codes,
        ];
    }

    /** @return list<array{id: string, label: string, iso2: string, key: string|null}> */
    public function countries(): array
    {
        return $this->addresses->countries();
    }

    /** @return list<array{id: string, label: string, code: string|null}> */
    public function states(?string $countryId): array
    {
        return $this->addresses->states($countryId);
    }

    /** @return list<array{id: string, label: string}> */
    public function cities(?string $stateId, ?string $countryId = null): array
    {
        return $this->addresses->cities($stateId, $countryId);
    }

    /** @return list<array{id: string, label: string, type: string, level: int|null}> */
    public function administrativeDistricts(?string $countryId, ?string $stateId = null): array
    {
        return $this->addresses->administrativeDistricts($countryId, $stateId);
    }

    /** @return list<array{id: string, label: string, type: string, level: int|null}> */
    public function administrativeSubdivisions(?string $districtId, ?string $countryId = null, ?string $stateId = null): array
    {
        return $this->addresses->administrativeSubdivisions($districtId, $countryId, $stateId);
    }

    /** @return list<array{id: string, label: string, type: string, level: int|null}> */
    public function addressAreas(?string $countryId = null, ?string $parentId = null, ?int $level = null): array
    {
        return $this->addresses->addressAreas($countryId, $parentId, $level);
    }

    /** @return list<array{id: string, label: string}> */
    public function languages(): array
    {
        return $this->languages->options();
    }

    /** @return list<array{id: string, label: string, code: string}> */
    public function taxonomyTerms(string $taxonomyCode, ?string $search = null, int $limit = 50): array
    {
        return $this->taxonomy->terms($taxonomyCode, $search, $limit);
    }

    /** @return list<array{id: string, label: string}> */
    public function references(?string $search = null, int $limit = 50): array
    {
        return $this->references->references($search, $limit);
    }

    /** @return list<array{id: string, label: string}> */
    public function submitInstitutions(?User $user, ?string $search = null, int $limit = 50): array
    {
        return $this->submissions->institutions($user?->getKey() !== null ? (string) $user->getKey() : null, $search, $limit);
    }

    /** @return list<array{id: string, label: string}> */
    public function submitPersons(?User $user, ?string $search = null, int $limit = 50): array
    {
        return $this->submissions->persons($user?->getKey() !== null ? (string) $user->getKey() : null, $search, $limit);
    }

    /** @return list<array{id: string, label: string}> */
    public function referenceAuthors(?string $search = null, int $limit = 50): array
    {
        return $this->references->authors($search, $limit);
    }

    /** @return list<array{id: string, label: string}> */
    public function venues(?string $search = null, int $limit = 50): array
    {
        return $this->places->venues($search, $limit);
    }

    /** @return list<array{id: string, label: string}> */
    public function spaces(?string $institutionId = null): array
    {
        return $this->places->spaces($institutionId);
    }

    /** @return list<array{id: string, label: string, slug: string}> */
    public function membershipApplicationSubjects(MemberSubjectType $subjectType, string $search): array
    {
        return $this->membershipSubjects->subjects($subjectType, $search);
    }

    /** @return list<array{id: string, label: string}> */
    public function prayerInstitutions(string $search): array
    {
        return $this->prayerInstitutions->verifiedInstitutions($search);
    }

    /** @return array<string, mixed> */
    public function advancedBuilderContext(User $user, ?string $requestedInstitutionId = null): array
    {
        return $this->advancedBuilder->forAccount((string) $user->getKey(), $requestedInstitutionId);
    }

    /** @return array<string, string> */
    public function institutionRoleOptions(): array
    {
        return $this->institutions->roleOptions();
    }
}
