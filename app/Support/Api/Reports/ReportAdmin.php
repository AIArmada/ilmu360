<?php

declare(strict_types=1);

namespace App\Support\Api\Reports;

use App\Actions\Reports\ResolveReportCategoryOptionsAction;
use App\Actions\Reports\SaveReportAction;
use App\Features\Reports\Api\ReportAdminContract;
use App\Features\Reports\Api\ReportCommands as ReportCommandsContract;
use App\Features\Reports\Api\ReportSnapshot;
use App\Features\Reports\Api\SaveReportInput;
use App\Models\Report;
use App\Shared\Reports\ReportSubjectResolver;
use App\Support\Api\Admin\Concerns\BuildsAdminFieldMetadata;
use Illuminate\Validation\Rule;

final readonly class ReportAdmin implements ReportAdminContract
{
    use BuildsAdminFieldMetadata;

    public function __construct(
        private ReportCommandsContract $commands,
        private SaveReportAction $saveReportAction,
        private ResolveReportCategoryOptionsAction $resolveReportCategoryOptionsAction,
        private ReportSubjectResolver $reportSubjects,
    ) {}

    public function fields(): array
    {
        return [
            $this->field('entity_type', 'string', required: true, default: 'event', allowedValues: $this->reportSubjects->validKeys(), meta: [
                'mutation_semantics' => 'replace_scalar',
                'paired_with' => 'entity_id',
            ]),
            $this->field('entity_id', 'string', required: true, meta: [
                'mutation_semantics' => 'replace_scalar',
                'paired_with' => 'entity_type',
                'relation_lookup' => 'resolved_from_entity_type',
            ]),
            $this->field('category', 'string', required: true, default: 'wrong_info', allowedValues: $this->resolveReportCategoryOptionsAction->validKeys(), meta: [
                'mutation_semantics' => 'replace_scalar',
                'allowed_values_resolved_from' => 'entity_type',
            ]),
            $this->field('description', 'string', required: false, maxLength: 2000, meta: $this->trimmedStringMutationMeta()),
            $this->field('status', 'string', required: true, default: 'open', allowedValues: ['open', 'triaged', 'resolved', 'dismissed']),
            $this->field('reporter_id', 'string', required: false, meta: $this->nullableRelationScalarMeta('users')),
            $this->field('handled_by', 'string', required: false, meta: $this->nullableRelationScalarMeta('users')),
            $this->field('resolution_note', 'string', required: false, maxLength: 2000, meta: $this->trimmedStringMutationMeta()),
            $this->field('evidence', 'array<file>', required: false, acceptedMimeTypes: $this->evidenceMimeTypes(), maxFileSizeKb: $this->maxUploadSizeKb(), maxFiles: 8, meta: $this->multipleMediaFieldMutationMeta('clear_evidence', explicitNull: 'preserve_existing_collection')),
            $this->field('clear_evidence', 'boolean', required: false, default: false),
        ];
    }

    public function rules(bool $updating = false): array
    {
        $required = $updating ? 'required' : 'required';
        $maxUploadSize = 'max:'.$this->maxUploadSizeKb();

        return [
            'entity_type' => [$required, 'string', Rule::in($this->reportSubjects->validKeys())],
            'entity_id' => [$required, 'uuid'],
            'category' => [$required, 'string', Rule::in($this->resolveReportCategoryOptionsAction->validKeys())],
            'description' => ['nullable', 'string', 'max:2000', 'required_if:category,other'],
            'status' => [$required, Rule::in(['open', 'triaged', 'resolved', 'dismissed'])],
            'reporter_id' => ['nullable', 'uuid', 'exists:users,id'],
            'handled_by' => ['nullable', 'uuid', 'exists:users,id'],
            'resolution_note' => ['nullable', 'string', 'max:2000'],
            'evidence' => ['nullable', 'array', 'max:8'],
            'evidence.*' => ['file', 'mimetypes:image/jpeg,image/png,image/webp,application/pdf', $maxUploadSize],
            'clear_evidence' => ['sometimes', 'boolean'],
        ];
    }

    public function createDefaults(): array
    {
        return [
            'entity_type' => 'event',
            'entity_id' => null,
            'category' => 'wrong_info',
            'description' => null,
            'status' => 'open',
            'reporter_id' => null,
            'handled_by' => null,
            'resolution_note' => null,
            'clear_evidence' => false,
        ];
    }

    public function defaultsFor(string $reportId): array
    {
        $record = Report::query()->findOrFail($reportId);

        return [
            'entity_type' => (string) $record->entity_type,
            'entity_id' => (string) $record->entity_id,
            'category' => (string) $record->category,
            'description' => $record->description,
            'status' => (string) $record->getAttribute('status'),
            'reporter_id' => $record->reporter_id !== null ? (string) $record->reporter_id : null,
            'handled_by' => $record->handled_by !== null ? (string) $record->handled_by : null,
            'resolution_note' => $record->resolution_note,
            'clear_evidence' => false,
        ];
    }

    public function normalize(array $payload, ?string $reportId = null): array
    {
        $report = $reportId !== null ? Report::query()->find($reportId) : null;

        return array_merge($payload, $this->saveReportAction->normalize($payload, $report));
    }

    public function save(array $payload, ?string $reportId = null): ReportSnapshot
    {
        return $this->commands->save(new SaveReportInput($payload, $reportId));
    }

    /**
     * @return list<string>
     */
    private function evidenceMimeTypes(): array
    {
        return ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
    }
}
