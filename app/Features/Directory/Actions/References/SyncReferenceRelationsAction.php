<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\References;

use App\Platform\Directory\EntityRelationSync;
use Illuminate\Database\Eloquent\Model;

/**
 * Sync reference relations that live outside the references table.
 */
final readonly class SyncReferenceRelationsAction
{
    public function __construct(
        private EntityRelationSync $entityRelationSync,
        private SyncReferenceContributorsAction $syncContributors,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(Model $reference, array $payload): void
    {
        if (array_key_exists('social_media', $payload)) {
            $this->entityRelationSync->syncSocialMedia($reference, $payload['social_media']);
        }

        if (array_key_exists('author_ids', $payload)) {
            $this->syncContributors->sync($reference, $payload['author_ids']);
        }
    }
}
