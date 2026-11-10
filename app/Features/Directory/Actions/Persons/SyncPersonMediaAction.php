<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Persons;

use App\Platform\Media\MediaCollectionSync;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Spatie\MediaLibrary\HasMedia;

/**
 * Synchronize person media collections from admin payloads.
 */
final readonly class SyncPersonMediaAction
{
    public function __construct(
        private MediaCollectionSync $mediaSync,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Model $person, array $data): void
    {
        if (! $person instanceof HasMedia) {
            throw new RuntimeException('People must support media.');
        }

        foreach ([
            'clear_avatar' => 'avatar',
            'clear_cover' => 'cover',
            'clear_main' => 'main',
            'clear_gallery' => 'gallery',
        ] as $flag => $collection) {
            if (($data[$flag] ?? false) === true) {
                $this->mediaSync->clearCollection($person, $collection);
            }
        }

        $avatar = $data['avatar'] ?? null;
        $main = $data['main'] ?? null;
        $cover = $data['cover'] ?? null;
        $gallery = $data['gallery'] ?? null;

        $this->mediaSync->syncSingle($person, $avatar instanceof UploadedFile ? $avatar : null, 'avatar');
        $this->mediaSync->syncSingle($person, $main instanceof UploadedFile ? $main : null, 'main');
        $this->mediaSync->syncSingle($person, $cover instanceof UploadedFile ? $cover : null, 'cover');
        $this->mediaSync->syncMultiple(
            $person,
            is_array($gallery) ? $gallery : null,
            'gallery',
            replace: is_array($gallery),
        );
    }
}
