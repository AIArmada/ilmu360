<?php

declare(strict_types=1);

namespace App\Features\Directory\Persistence;

use App\Features\Directory\Api\PublicDirectoryCacheVersions;
use App\Features\Events\Api\EventInvolvementFingerprint;
use App\Platform\Cache\CacheVersionStore;

final readonly class PublicDirectoryCacheVersionStore implements PublicDirectoryCacheVersions
{
    private const string INSTITUTION_VERSION_KEY = 'public_directory:institutions:version:v1';

    private const string PERSON_VERSION_KEY = 'public_directory:persons:version:v1';

    private const string INSTITUTION_MORPH_ALIAS = 'institution';

    private const string PERSON_MORPH_ALIAS = 'person';

    public function __construct(
        private CacheVersionStore $cacheVersions,
        private EventInvolvementFingerprint $eventInvolvementFingerprint,
    ) {}

    /**
     * @return array{version: string}
     */
    public function institution(): array
    {
        return [
            'version' => $this->cacheVersions->version(self::INSTITUTION_VERSION_KEY),
        ];
    }

    /**
     * @return array{version: string}
     */
    public function person(): array
    {
        $eventInvolvementFingerprint = $this->eventInvolvementFingerprint->current();

        return [
            'version' => implode('|', [
                $this->cacheVersions->version(self::PERSON_VERSION_KEY),
                $eventInvolvementFingerprint,
            ]),
        ];
    }

    public function bumpInstitution(): void
    {
        $this->cacheVersions->bump(self::INSTITUTION_VERSION_KEY);
    }

    public function bumpPerson(): void
    {
        $this->cacheVersions->bump(self::PERSON_VERSION_KEY);
    }

    public function bumpAll(): void
    {
        $this->bumpInstitution();
        $this->bumpPerson();
    }

    /**
     * @param  list<string>  $addressableMorphTypes
     */
    public function bumpForAddressableTypes(array $addressableMorphTypes): void
    {
        foreach ($addressableMorphTypes as $morphType) {
            $this->bumpForMorphType($morphType);
        }
    }

    public function bumpForMediaType(string $mediaMorphType): void
    {
        $this->bumpForMorphType($mediaMorphType);
    }

    public function bumpForEvent(): void
    {
        $this->bumpInstitution();
        $this->bumpPerson();
    }

    public function bumpForEventKeyPerson(): void
    {
        $this->bumpPerson();
    }

    private function bumpForMorphType(string $morphType): void
    {
        if ($morphType === self::INSTITUTION_MORPH_ALIAS) {
            $this->bumpInstitution();

            return;
        }

        if ($morphType === self::PERSON_MORPH_ALIAS) {
            $this->bumpPerson();
        }
    }
}
