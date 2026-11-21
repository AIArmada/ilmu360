<?php

declare(strict_types=1);

namespace App\Features\Reports\EntryPoints\Admin\RecordActions;

use App\Features\Reports\Api\ReportTriageContract;
use App\Features\Reports\EntryPoints\Admin\AdminReportTriageService;
use App\Models\User;
use App\Platform\Api\Admin\Concerns\BuildsWorkflowActionDescriptors;
use App\Platform\Api\Admin\Contracts\RecordActionContributor;
use Illuminate\Database\Eloquent\Model;

/**
 * Contributes report triage follow-up actions to the admin record action
 * surface.
 */
final readonly class ReportRecordActionContributor implements RecordActionContributor
{
    use BuildsWorkflowActionDescriptors;

    public function __construct(
        private AdminReportTriageService $reportTriageService,
        private ReportTriageContract $reportTriageContract,
    ) {}

    public function supports(string $surface, string $resourceKey, Model $record): bool
    {
        return $surface === RecordActionContributor::SURFACE_ADMIN
            && $resourceKey === 'reports';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function actions(string $surface, string $resourceKey, Model $record, ?User $actor = null): array
    {
        if (! $this->supports($surface, $resourceKey, $record)) {
            return [];
        }

        return $this->reportWorkflowActions((string) $record->getRouteKey(), $actor);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reportWorkflowActions(string $recordKey, ?User $actor = null): array
    {
        if (! $this->reportTriageContract->canTriage($actor)) {
            return [];
        }

        $schema = $this->trySchema(fn (): array => $this->reportTriageService->schema($recordKey, $actor));

        if (! is_array($schema)) {
            return [];
        }

        return [
            $this->workflowSchemaActionDescriptor(
                key: 'get_report_triage_schema',
                label: 'Fetch report triage schema',
                description: 'Read the explicit triage workflow schema for this report before choosing the action.',
                tool: 'admin-get-report-triage-schema',
                recordKey: $recordKey,
                schema: $schema,
            ),
            $this->workflowActionDescriptor(
                key: 'triage_report',
                label: 'Triage report',
                description: 'Run one explicit triage action that is currently valid for this report.',
                tool: 'admin-triage-report',
                recordKey: $recordKey,
                schemaTool: 'admin-get-report-triage-schema',
                schemaActionKey: 'get_report_triage_schema',
                schema: $schema,
            ),
        ];
    }
}
