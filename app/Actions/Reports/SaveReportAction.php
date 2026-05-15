<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Models\Report;
use App\Models\User;
use App\Support\Media\ModelMediaSyncService;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class SaveReportAction
{
    use AsAction;

    public function __construct(
        private ResolveReportCategoryOptionsAction $resolveReportCategoryOptionsAction,
        private ResolveReportEntityMetadataAction $resolveReportEntityMetadataAction,
        private ModelMediaSyncService $mediaSyncService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?Report $report = null): Report
    {
        $creating = ! $report instanceof Report;
        $report ??= new Report;

        $entityType = $this->normalizeEntityType($data['entity_type'] ?? $report->entity_type ?? null);
        $entityId = $this->normalizeEntityId($entityType, $data['entity_id'] ?? $report->entity_id ?? null);

        $report->fill([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'category' => $this->normalizeCategory($entityType, $data['category'] ?? $report->category ?? null),
            'description' => array_key_exists('description', $data)
                ? $this->normalizeOptionalString($data['description'])
                : $report->description,
            'status' => array_key_exists('status', $data)
                ? $this->normalizeStatus($data['status'])
                : $this->normalizeStatus($report->status ?? ($creating ? 'open' : null)),
            'reporter_id' => array_key_exists('reporter_id', $data)
                ? $this->normalizeOptionalUserKey($data['reporter_id'], 'reporter_id')
                : ($creating ? null : $report->reporter_id),
            'handled_by' => array_key_exists('handled_by', $data)
                ? $this->normalizeOptionalUserKey($data['handled_by'], 'handled_by')
                : ($creating ? null : $report->handled_by),
            'resolution_note' => array_key_exists('resolution_note', $data)
                ? $this->normalizeOptionalString($data['resolution_note'])
                : $report->resolution_note,
        ]);

        $report->save();

        if (($data['clear_evidence'] ?? false) === true) {
            $this->mediaSyncService->clearCollection($report, 'evidence');
        }

        $this->mediaSyncService->syncMultiple(
            $report,
            is_array($data['evidence'] ?? null) ? $data['evidence'] : null,
            'evidence',
            replace: is_array($data['evidence'] ?? null),
        );

        return $report->fresh(['entity', 'reporter', 'handler', 'media']) ?? $report;
    }

    private function normalizeEntityType(mixed $value): string
    {
        $entityType = $this->normalizeRequiredString($value, 'entity_type');

        if (! in_array($entityType, $this->resolveReportEntityMetadataAction->validKeys(), true)) {
            throw ValidationException::withMessages([
                'entity_type' => __('The selected report entity type is invalid.'),
            ]);
        }

        return $entityType;
    }

    private function normalizeEntityId(string $entityType, mixed $value): string
    {
        $entityId = $this->normalizeRequiredString($value, 'entity_id');
        $entityMetadata = $this->resolveReportEntityMetadataAction->handle($entityType);
        $modelClass = $entityMetadata['model_class'];

        if (! $modelClass::query()->whereKey($entityId)->exists()) {
            throw ValidationException::withMessages([
                'entity_id' => __('The selected report entity is invalid.'),
            ]);
        }

        return $entityId;
    }

    private function normalizeCategory(string $entityType, mixed $value): string
    {
        $category = $this->normalizeRequiredString($value, 'category');

        if (! in_array($category, $this->resolveReportCategoryOptionsAction->validKeys($entityType), true)) {
            throw ValidationException::withMessages([
                'category' => __('The selected report category is invalid for this entity type.'),
            ]);
        }

        return $category;
    }

    private function normalizeStatus(mixed $value): string
    {
        $status = $this->normalizeRequiredString($value, 'status');

        if (! in_array($status, ['open', 'triaged', 'resolved', 'dismissed'], true)) {
            throw ValidationException::withMessages([
                'status' => __('The selected report status is invalid.'),
            ]);
        }

        return $status;
    }

    private function normalizeOptionalUserKey(mixed $value, string $field): ?string
    {
        $userKey = $this->normalizeOptionalString($value);

        if ($userKey === null) {
            return null;
        }

        if (! User::query()->whereKey($userKey)->exists()) {
            throw ValidationException::withMessages([
                $field => __('The selected user is invalid.'),
            ]);
        }

        return $userKey;
    }

    private function normalizeRequiredString(mixed $value, string $field): string
    {
        $normalized = $this->normalizeOptionalString($value);

        if ($normalized === null) {
            throw ValidationException::withMessages([
                $field => __('This field is required.'),
            ]);
        }

        return $normalized;
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
