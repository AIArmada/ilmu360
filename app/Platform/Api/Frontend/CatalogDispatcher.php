<?php

declare(strict_types=1);

namespace App\Platform\Api\Frontend;

use App\Features\Contributions\Api\SubmissionCatalog;
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
    ) {}

    public function countries(): array
    {
        return $this->addresses->countries();
    }

    public function states(?string $countryId): array
    {
        return $this->addresses->states($countryId);
    }

    public function cities(?string $stateId, ?string $countryId = null): array
    {
        return $this->addresses->cities($stateId, $countryId);
    }

    public function administrativeDistricts(?string $countryId, ?string $stateId = null): array
    {
        return $this->addresses->administrativeDistricts($countryId, $stateId);
    }

    public function administrativeSubdivisions(?string $districtId, ?string $countryId = null, ?string $stateId = null): array
    {
        return $this->addresses->administrativeSubdivisions($districtId, $countryId, $stateId);
    }

    public function addressAreas(?string $countryId = null, ?string $parentId = null, ?int $level = null): array
    {
        return $this->addresses->addressAreas($countryId, $parentId, $level);
    }

    public function languages(): array
    {
        return $this->languages->options();
    }

    public function taxonomyTerms(string $taxonomyCode, ?string $search = null, int $limit = 50): array
    {
        return $this->taxonomy->terms($taxonomyCode, $search, $limit);
    }

    public function references(?string $search = null, int $limit = 50): array
    {
        return $this->references->references($search, $limit);
    }

    public function submitInstitutions(?User $user, ?string $search = null, int $limit = 50): array
    {
        return $this->submissions->institutions($user?->getKey() !== null ? (string) $user->getKey() : null, $search, $limit);
    }

    public function submitPersons(?User $user, ?string $search = null, int $limit = 50): array
    {
        return $this->submissions->persons($user?->getKey() !== null ? (string) $user->getKey() : null, $search, $limit);
    }

    public function referenceAuthors(?string $search = null, int $limit = 50): array
    {
        return $this->references->authors($search, $limit);
    }

    public function venues(?string $search = null, int $limit = 50): array
    {
        return $this->places->venues($search, $limit);
    }

    public function spaces(?string $institutionId = null): array
    {
        return $this->places->spaces($institutionId);
    }

    public function membershipApplicationSubjects(MemberSubjectType $subjectType, string $search): array
    {
        return $this->membershipSubjects->subjects($subjectType, $search);
    }

    public function prayerInstitutions(string $search): array
    {
        return $this->prayerInstitutions->verifiedInstitutions($search);
    }

    public function advancedBuilderContext(User $user, ?string $requestedInstitutionId = null): array
    {
        return $this->advancedBuilder->forAccount((string) $user->getKey(), $requestedInstitutionId);
    }

    public function institutionRoleOptions(): array
    {
        return $this->institutions->roleOptions();
    }
}
