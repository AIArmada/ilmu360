<?php

declare(strict_types=1);

namespace App\Support\Api\Admin;

use App\Filament\Resources\Reports\ReportResource;
use App\Models\Report;
use App\Models\User;
use App\Notifications\ReportResolvedNotification;
use App\Support\Moderation\ReportTriageWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final readonly class AdminReportTriageService
{
    public function __construct(
        private AdminResourceRegistry $registry,
    ) {}

    public function canTriage(?User $actor = null): bool
    {
        return $actor instanceof User && $actor->hasAnyRole(['super_admin', 'admin', 'moderator']);
    }

    /**
     * @return array{data: array{resource: array<string, mixed>, record: array<string, mixed>, schema: array<string, mixed>}}
     */
    public function schema(string $recordKey, ?User $actor = null): array
    {
        abort_unless($this->canTriage($actor), 403);

        $report = $this->resolveReport($recordKey);
        $availableActions = ReportTriageWorkflow::availableActions($report);
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
        $availableActions = ReportTriageWorkflow::availableActions($report);

        $validated = Validator::make($payload, [
            'action' => ['required', 'string', Rule::in(array_keys($availableActions))],
            'resolution_note' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $report = DB::transaction(function () use ($report, $actor, $validated): Report {
            $action = (string) $validated['action'];
            $resolutionNote = is_string($validated['resolution_note'] ?? null) && trim($validated['resolution_note']) !== ''
                ? trim($validated['resolution_note'])
                : null;

            match ($action) {
                'triage' => $report->forceFill([
                    'status' => 'triaged',
                    'handled_by' => $actor->getKey(),
                    'resolution_note' => $resolutionNote,
                ])->save(),
                'resolve' => $report->forceFill([
                    'status' => 'resolved',
                    'handled_by' => $actor->getKey(),
                    'resolution_note' => $resolutionNote,
                ])->save(),
                'dismiss' => $report->forceFill([
                    'status' => 'dismissed',
                    'handled_by' => $actor->getKey(),
                    'resolution_note' => $resolutionNote,
                ])->save(),
                'reopen' => $report->forceFill([
                    'status' => 'open',
                    'handled_by' => null,
                    'resolution_note' => null,
                ])->save(),
                default => throw new \InvalidArgumentException('Unsupported report triage action.'),
            };

            $report->loadMissing(['reporter', 'entity']);

            if (in_array($action, ['resolve', 'dismiss'], true) && $report->reporter instanceof User) {
                $report->reporter->notify(new ReportResolvedNotification($report));
            }

            return $report->fresh(['entity', 'reporter', 'handler']) ?? $report;
        });

        return [
            'data' => [
                'resource' => $this->registry->metadata(ReportResource::class),
                'record' => $this->registry->serializeRecordDetail(ReportResource::class, $report),
            ],
        ];
    }

    private function resolveReport(string $recordKey): Report
    {
        /** @var Report $report */
        $report = $this->registry->resolveRecord(ReportResource::class, $recordKey);

        return $report;
    }
}
