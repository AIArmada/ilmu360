<?php

declare(strict_types=1);

namespace App\Support\Api\Reports;

use App\Features\Reports\Api\ReportTriageContract;
use App\Shared\Auth\AccountActor;

final class ReportTriage implements ReportTriageContract
{
    public function canTriage(?AccountActor $actor): bool
    {
        return $actor?->hasAnyRole(['super_admin', 'admin', 'moderator']) ?? false;
    }

    public function availableActions(string $status): array
    {
        return match ($status) {
            'open' => [
                'triage' => $this->definition(
                    label: 'Mark Triaged',
                    description: 'Assign the report to a moderator and move it into triage for follow-up.',
                ),
                'resolve' => $this->definition(
                    label: 'Resolve',
                    description: 'Mark the report as resolved and notify the original reporter.',
                ),
                'dismiss' => $this->definition(
                    label: 'Dismiss',
                    description: 'Dismiss the report and notify the original reporter.',
                ),
            ],
            'triaged' => [
                'resolve' => $this->definition(
                    label: 'Resolve',
                    description: 'Mark the triaged report as resolved and notify the original reporter.',
                ),
                'dismiss' => $this->definition(
                    label: 'Dismiss',
                    description: 'Dismiss the triaged report and notify the original reporter.',
                ),
                'reopen' => $this->definition(
                    label: 'Reopen',
                    description: 'Return the triaged report to the open queue.',
                ),
            ],
            'resolved', 'dismissed' => [
                'reopen' => $this->definition(
                    label: 'Reopen',
                    description: 'Return this closed report to the open queue for another review pass.',
                ),
            ],
            default => [],
        };
    }

    /**
     * @return array{label: string, description: string, requires_resolution_note: bool}
     */
    private function definition(string $label, string $description, bool $requiresResolutionNote = false): array
    {
        return [
            'label' => $label,
            'description' => $description,
            'requires_resolution_note' => $requiresResolutionNote,
        ];
    }
}
