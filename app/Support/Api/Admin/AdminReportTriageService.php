<?php

declare(strict_types=1);

namespace App\Support\Api\Admin;

use App\Features\Reports\Api\ReportCommands;
use App\Features\Reports\Api\ReportReader;
use App\Features\Reports\Api\ReportSnapshot;
use App\Features\Reports\Api\ReportTriageContract;
use App\Features\Reports\Api\TriageReportInput;
use App\Filament\Resources\Reports\ReportResource;
use App\Models\Report;
use App\Models\User;
use App\Support\Api\Admin\Concerns\ProvidesWorkflowResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final readonly class AdminReportTriageService
{
    use ProvidesWorkflowResponse;

    public function __construct(
        private AdminResourceRegistry $registry,
        private ReportReader $reader,
        private ReportTriageContract $triage,
        private ReportCommands $commands,
    ) {}

    public function canTriage(?User $actor = null): bool
    {
        return $this->triage->canTriage($actor);
    }

    /**
     * @return array{data: array{resource: array<string, mixed>, record: array<string, mixed>, schema: array<string, mixed>}}
     */
    public function schema(string $recordKey, ?User $actor = null): array
    {
        abort_unless($this->canTriage($actor), 403);

        $report = $this->resolveReport($recordKey);
        $availableActions = $this->availableActionsFor($report);
        $defaultAction = array_key_first($availableActions);

        return [
            'data' => [
                'resource' => $this->registry->metadata(ReportResource::class),
                'record' => $this->registry->serializeRecordDetail(ReportResource::class, $report),
                'schema' => [
                    'action' => 'triage_report',
                    'method' => 'POST',
                    'endpoint' => route('api.admin.reports.triage', ['recordKey' => $report->getRouteKey()], false),
                    'defaults' => [
                        'action' => $defaultAction,
                        'resolution_note' => null,
                    ],
                    'available_actions' => array_values(array_map(
                        static fn (string $key, array $definition): array => ['key' => $key] + $definition,
                        array_keys($availableActions),
                        array_values($availableActions),
                    )),
                    'fields' => [
                        [
                            'name' => 'action',
                            'type' => 'string',
                            'required' => true,
                            'default' => $defaultAction,
                            'allowed_values' => array_keys($availableActions),
                        ],
                        [
                            'name' => 'resolution_note',
                            'type' => 'string',
                            'required' => false,
                            'max_length' => 2000,
                        ],
                    ],
                    'conditional_rules' => [],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{data: array{resource: array<string, mixed>, record: array<string, mixed>}}
     */
    public function triage(string $recordKey, array $payload, ?User $actor = null): array
    {
        abort_unless($this->canTriage($actor), 403);
        abort_unless($actor instanceof User, 403);

        $report = $this->resolveReport($recordKey);
        $availableActions = $this->availableActionsFor($report);

        $validated = Validator::make($payload, [
            'action' => ['required', 'string', Rule::in(array_keys($availableActions))],
            'resolution_note' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $resolutionNote = is_string($validated['resolution_note'] ?? null) && trim($validated['resolution_note']) !== ''
            ? trim($validated['resolution_note'])
            : null;

        $this->commands->triage(new TriageReportInput(
            reportId: (string) $report->getKey(),
            action: (string) $validated['action'],
            resolutionNote: $resolutionNote,
            actor: $actor,
        ));

        return $this->workflowResponse(ReportResource::class, $this->resolveReport($recordKey));
    }

    /**
     * @return array<string, array{label: string, description: string, requires_resolution_note: bool}>
     */
    private function availableActionsFor(Report $report): array
    {
        $snapshot = $this->reader->find((string) $report->getKey());

        return $this->triage->availableActions(
            $snapshot instanceof ReportSnapshot ? $snapshot->status : (string) $report->getAttribute('status'),
        );
    }

    private function resolveReport(string $recordKey): Report
    {
        /** @var Report $report */
        $report = $this->registry->resolveRecord(ReportResource::class, $recordKey);

        return $report;
    }
}
