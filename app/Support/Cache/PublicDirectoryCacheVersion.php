<?php

namespace App\Support\Cache;

use App\Models\Address;
use App\Models\Event;
use App\Models\EventKeyPerson;
use App\Models\Institution;
use App\Models\Speaker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class PublicDirectoryCacheVersion
{
    private const string INSTITUTION_DIRECTORY_VERSION_KEY = 'public_directory:institutions:version:v1';

    private const string SPEAKER_DIRECTORY_VERSION_KEY = 'public_directory:speakers:version:v1';

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
    public function speaker(): array
    {
        return [
            'version' => $this->compositeVersion(self::SPEAKER_DIRECTORY_VERSION_KEY),
        ];
    }

    public function bumpInstitution(): void
    {
        $this->storeVersion(self::INSTITUTION_DIRECTORY_VERSION_KEY);
    }

    public function bumpSpeaker(): void
    {
        $this->storeVersion(self::SPEAKER_DIRECTORY_VERSION_KEY);
    }

    public function bumpAll(): void
    {
        $this->bumpInstitution();
        $this->bumpSpeaker();
    }

    public function bumpForAddress(Address $address): void
    {
        $address->loadMissing('addressable');

        $addressable = $address->addressable;

        if ($addressable instanceof Institution) {
            $this->bumpInstitution();

            return;
        }

        if ($addressable instanceof Speaker) {
            $this->bumpSpeaker();
        }
    }

    public function bumpForMedia(Media $media): void
    {
        $modelType = (string) $media->model_type;

        if (in_array($modelType, [(new Institution)->getMorphClass(), Institution::class], true)) {
            $this->bumpInstitution();

            return;
        }

        if (in_array($modelType, [(new Speaker)->getMorphClass(), Speaker::class], true)) {
            $this->bumpSpeaker();
        }
    }

    public function bumpForEvent(Event $event): void
    {
        $this->bumpInstitution();
        $this->bumpSpeaker();
    }

    public function bumpForEventKeyPerson(EventKeyPerson $eventKeyPerson): void
    {
        $this->bumpSpeaker();
    }

    private function versionFor(string $key): string
    {
        /** @var string $version */
        $version = Cache::rememberForever($key, static fn (): string => (string) Str::ulid());

        return $version;
    }

    private function compositeVersion(string $key): string
    {
        return sha1(implode('|', [
            $this->versionFor($key),
            $this->publicCountryFingerprint(),
        ]));
    }

    private function publicCountryFingerprint(): string
    {
        return sha1(json_encode(config('public-countries', [])) ?: '');
    }

    private function storeVersion(string $key): void
    {
        Cache::forever($key, (string) Str::ulid());
    }
}
