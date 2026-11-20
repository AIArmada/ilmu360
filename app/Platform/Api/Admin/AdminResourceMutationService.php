<?php

declare(strict_types=1);

namespace App\Platform\Api\Admin;

use App\Models\User;
use App\Platform\Api\Resources\AbstractAdminResourceWriter;
use App\Platform\Api\Resources\EloquentResourceWriterSupport;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Thin admin write dispatcher over the owner-registered resource writers.
 */
class AdminResourceMutationService
{
    public function __construct(
        private readonly AdminResourceRegistry $registry,
        private readonly EloquentResourceWriterSupport $support,
    ) {}

    /** @param class-string $resourceClass */
    public function supports(string $resourceClass): bool
    {
        return $this->registry->writer($resourceClass) instanceof AbstractAdminResourceWriter;
    }

    /**
     * @param  class-string  $resourceClass
     * @return array<string, mixed>
     */
    public function schema(string $resourceClass, string $resourceKey, string $operation = 'create', ?Model $record = null): array
    {
        return $this->writerFor($resourceClass)->schemaFor($resourceKey, $operation, $record);
    }

    /**
     * @param  class-string  $resourceClass
     * @return array<string, mixed>
     */
    public function rules(string $resourceClass, bool $updating = false): array
    {
        return $this->writerFor($resourceClass)->rulesFor($updating);
    }

    /**
     * @param  class-string  $resourceClass
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function normalizeValidatedPayload(string $resourceClass, array $validated, ?Model $record = null): array
    {
        return $this->writerFor($resourceClass)->normalizeFor($validated, $record);
    }

    /**
     * @param  class-string  $resourceClass
     * @param  array<string, mixed>  $validated
     */
    public function store(string $resourceClass, array $validated, User $actor): Model
    {
        return $this->writerFor($resourceClass)->storeModel($actor, $validated);
    }

    /**
     * @param  class-string  $resourceClass
     * @param  array<string, mixed>  $validated
     */
    public function update(string $resourceClass, Model $record, array $validated, User $actor): Model
    {
        return $this->writerFor($resourceClass)->updateModel($actor, $record, $validated);
    }

    /**
     * @param  array<string, mixed>  $normalizedPayload
     * @return array{normalized_payload: array<string, mixed>, warnings: list<array<string, string>>, destructive_media_fields: list<string>}
     */
    public function previewNormalizedPayload(array $normalizedPayload): array
    {
        return $this->support->previewNormalizedPayload($normalizedPayload);
    }

    private function writerFor(string $resourceClass): AbstractAdminResourceWriter
    {
        $writer = $this->registry->writer($resourceClass);

        if (! $writer instanceof AbstractAdminResourceWriter) {
            throw new NotFoundHttpException;
        }

        return $writer;
    }
}
