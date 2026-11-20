<?php

declare(strict_types=1);

namespace App\Features\Inspirations\EntryPoints\Api\Admin;

use App\Features\Inspirations\Api\InspirationAdminContract;
use App\Models\User;
use App\Platform\Admin\Concerns\BuildsAdminFieldMetadata;
use App\Platform\Api\Admin\AdminMutationHookRegistry;
use App\Platform\Api\Resources\AbstractAdminResourceWriter;
use App\Platform\Api\Resources\ResourceDefinitionContext;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Spatie\MediaLibrary\HasMedia;

final class InspirationAdminResourceWriter extends AbstractAdminResourceWriter
{
    use BuildsAdminFieldMetadata;

    public function __construct(
        ResourceDefinitionContext $context,
        string $resourceClass,
        private readonly InspirationAdminContract $inspirationAdmin,
        private readonly AdminMutationHookRegistry $hooks,
    ) {
        parent::__construct($context, $resourceClass);
    }

    /** @return array<string, mixed> */
    public function schemaFor(string $resourceKey, string $operation, ?Model $record): array
    {
        $updating = $operation === 'update';
        $defaults = $updating && $record instanceof Model
            ? $this->defaultsForRecord($record)
            : $this->defaultsForCreate();

        return [
            'resource_key' => $resourceKey,
            'operation' => $operation,
            'method' => $updating ? 'PUT' : 'POST',
            'endpoint' => $this->endpoint($resourceKey, $operation, $record),
            'content_type' => 'multipart/form-data',
            'slug_behavior' => 'not_applicable',
            'defaults' => $defaults,
            'current_media' => $record instanceof Model && $record instanceof HasMedia
                ? $this->mediaState($record, $this->inspirationAdmin->mediaCollections())
                : null,
            'fields' => $this->inspirationAdmin->fields(),
            'catalogs' => [],
            'conditional_rules' => [],
        ];
    }

    /** @return array<string, mixed> */
    public function rulesFor(bool $updating): array
    {
        return $this->inspirationAdmin->rules($updating);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function normalizeFor(array $validated, ?Model $record): array
    {
        return $this->inspirationAdmin->normalize(
            $validated,
            $record instanceof Model ? (string) $record->getKey() : null,
        );
    }

    /** @return array<string, mixed> */
    public function defaultsForCreate(): array
    {
        return $this->inspirationAdmin->createDefaults();
    }

    /** @return array<string, mixed> */
    public function defaultsForRecord(Model $record): array
    {
        $defaults = $this->inspirationAdmin->defaultsFor((string) $record->getKey());

        foreach ($this->hooks->all() as $hooks) {
            $defaults = $hooks->augmentDefaultsForRecord($record, $defaults);
        }

        return $defaults;
    }

    /** @param array<string, mixed> $validated */
    public function storeModel(User $actor, array $validated): Model
    {
        return $this->saveInspiration($validated);
    }

    /** @param array<string, mixed> $validated */
    public function updateModel(User $actor, Model $record, array $validated): Model
    {
        if ($record::class !== $this->inspirationAdmin->modelClass()) {
            throw new RuntimeException('Expected inspiration record.');
        }

        return $this->saveInspiration($validated, (string) $record->getKey());
    }

    /** @param array<string, mixed> $validated */
    private function saveInspiration(array $validated, ?string $inspirationId = null): Model
    {
        $snapshot = $this->inspirationAdmin->save($validated, $inspirationId);
        $modelClass = $this->resourceClass::getModel();

        return $modelClass::query()->with(['media'])->findOrFail($snapshot->id);
    }
}
