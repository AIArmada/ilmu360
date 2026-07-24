<?php

namespace App\Support\Cache;

use AIArmada\Addressing\Models\Address;
use App\Models\Event;
use App\Models\EventKeyPerson;
use App\Models\Institution;
use App\Models\Person;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class PublicDirectoryCacheVersion
{
    private const string INSTITUTION_DIRECTORY_VERSION_KEY = 'public_directory:institutions:version:v1';

    private const string PERSON_DIRECTORY_VERSION_KEY = 'public_directory:persons:version:v1';

    /**
     * @return array{version: string}
     */
    public function institution(): array
    {
        return [
            'version' => $this->compositeVersion(self::INSTITUTION_DIRECTORY_VERSION_KEY),
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
                $this->compositeVersion(self::PERSON_DIRECTORY_VERSION_KEY),
                $participationCount,
                (string) $latestParticipationChange,
            ]),
        ];
    }

    public function bumpInstitution(): void
    {
        $this->storeVersion(self::INSTITUTION_DIRECTORY_VERSION_KEY);
    }

    public function bumpPerson(): void
    {
        $this->storeVersion(self::PERSON_DIRECTORY_VERSION_KEY);
    }

    public function bumpAll(): void
    {
        $this->bumpInstitution();
        $this->bumpPerson();
    }

    public function bumpForAddress(Address $address): void
    {
        $address->loadMissing('addressableLinks.addressable');

        foreach ($address->addressableLinks as $link) {
            $addressable = $link->addressable;

            if ($addressable instanceof Institution) {
                $this->bumpInstitution();

                continue;
            }

            if ($addressable instanceof Person) {
                $this->bumpPerson();
            }
        }
    }

    public function bumpForMedia(Media $media): void
    {
        $modelType = (string) $media->model_type;

        if (in_array($modelType, [(new Institution)->getMorphClass(), Institution::class], true)) {
            $this->bumpInstitution();

            return;
        }

        if (in_array($modelType, [(new Person)->getMorphClass(), Person::class], true)) {
            $this->bumpPerson();
        }
    }

    public function bumpForEvent(Event $event): void
    {
        $this->bumpInstitution();
        $this->bumpPerson();
    }

    public function bumpForEventKeyPerson(EventKeyPerson $eventKeyPerson): void
    {
        $this->bumpPerson();
    }

    private function versionFor(string $key): string
    {
        /** @var string $version */
        $version = Cache::rememberForever($key, static fn (): string => (string) Str::ulid());

        return $version;
    }

    private function compositeVersion(string $key): string
    {
        return $this->versionFor($key);
    }

    private function storeVersion(string $key): void
    {
        Cache::forever($key, (string) Str::ulid());
    }
}
