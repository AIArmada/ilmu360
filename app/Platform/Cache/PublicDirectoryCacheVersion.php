<?php

declare(strict_types=1);

namespace App\Platform\Cache;

use AIArmada\Addressing\Models\Address;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final readonly class PublicDirectoryCacheVersion
{
    private const string INSTITUTION_VERSION_KEY = 'public_directory:institutions:version:v1';

    private const string PERSON_VERSION_KEY = 'public_directory:persons:version:v1';

    private const string INSTITUTION_MORPH_ALIAS = 'institution';

    private const string PERSON_MORPH_ALIAS = 'person';

    public function __construct(private CacheVersionStore $cacheVersions) {}

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
        $involvementsTable = config('events.database.tables.event_involvements', 'event_involvements');
        $participationCount = DB::table($involvementsTable)->count();
        $latestParticipationChange = DB::table($involvementsTable)->max('updated_at');

        return [
            'version' => implode('|', [
                $this->cacheVersions->version(self::PERSON_VERSION_KEY),
                $participationCount,
                (string) $latestParticipationChange,
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

    public function bumpForAddress(Address $address): void
    {
        $address->loadMissing('addressableLinks');

        foreach ($address->addressableLinks as $link) {
            $this->bumpForMorphType((string) $link->addressable_type);
        }
    }

    public function bumpForMedia(Media $media): void
    {
        $this->bumpForMorphType((string) $media->model_type);
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
        if ($this->isMorphType($morphType, self::INSTITUTION_MORPH_ALIAS)) {
            $this->bumpInstitution();

            return;
        }

        if ($this->isMorphType($morphType, self::PERSON_MORPH_ALIAS)) {
            $this->bumpPerson();
        }
    }

    private function isMorphType(string $modelType, string $alias): bool
    {
        return $modelType === $alias || $modelType === Relation::getMorphedModel($alias);
    }
}
