<?php

declare(strict_types=1);

namespace App\Features\Reports\EntryPoints\Api\Admin;

use App\Features\Reports\Api\ReportAdminContract;
use App\Features\Reports\Models\Report;
use App\Models\User;
use App\Platform\Admin\Concerns\BuildsAdminFieldMetadata;
use App\Platform\Api\Admin\AdminMutationHookRegistry;
use App\Platform\Api\Resources\AbstractAdminResourceWriter;
use App\Platform\Api\Resources\ResourceDefinitionContext;
use Illuminate\Database\Eloquent\Model;

final class ReportAdminResourceWriter extends AbstractAdminResourceWriter
{
    use BuildsAdminFieldMetadata;

    public function __construct(
        ResourceDefinitionContext $context,
        string $resourceClass,
        private readonly ReportAdminContract $reportAdmin,
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
            'current_media' => $record instanceof Model && $record instanceof \Spatie\MediaLibrary\HasMedia
                ? $this->mediaState($record, ['evidence'])
                : null,
            'fields' => $this->reportAdmin->fields(),
            'catalogs' => [],
            'conditional_rules' => [
                ['field' => 'description', 'required_when' => ['category' => ['other']]],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function rulesFor(bool $updating): array
    {
        return $this->reportAdmin->rules($updating);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function normalizeFor(array $validated, ?Model $record): array
    {
        return $this->reportAdmin->normalize(
            $validated,
            $record instanceof Model ? (string) $record->getKey() : null,
        );
    }

    /** @return array<string, mixed> */
    public function defaultsForCreate(): array
    {
        return $this->reportAdmin->createDefaults();
    }

    /** @return array<string, mixed> */
    public function defaultsForRecord(Model $record): array
    {
        $defaults = $this->reportAdmin->defaultsFor((string) $record->getKey());

        foreach ($this->hooks->all() as $hooks) {
            $defaults = $hooks->augmentDefaultsForRecord($record, $defaults);
        }

        return $defaults;
    }

    /** @param array<string, mixed> $validated */
    public function storeModel(User $actor, array $validated): Model
    {
        return $this->saveReport($validated);
    }

    /** @param array<string, mixed> $validated */
    public function updateModel(User $actor, Model $record, array $validated): Model
    {
        return $this->saveReport($validated, (string) $record->getKey());
    }

    /** @param array<string, mixed> $validated */
    private function saveReport(array $validated, ?string $reportId = null): Model
    {
        $snapshot = $this->reportAdmin->save($validated, $reportId);

        return Report::query()
            ->with(['entity', 'reporter', 'handler', 'media'])
            ->findOrFail($snapshot->id);
    }
}
