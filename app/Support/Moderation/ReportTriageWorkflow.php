<?php

declare(strict_types=1);

namespace App\Support\Moderation;

use App\Models\Report;

final class ReportTriageWorkflow
{
    /**
     * @return array<string, array{label: string, description: string, requires_resolution_note: bool}>
     */
    public static function availableActions(Report $report): array
    {
        return match ((string) $report->status) {
            'open' => [
                'triage' => self::definition(
                    label: 'Mark Triaged',
                    description: 'Assign the report to a moderator and move it into triage for follow-up.',
                ),
                'resolve' => self::definition(
                    label: 'Resolve',
                    description: 'Mark the report as resolved and notify the original reporter.',
                ),
                'dismiss' => self::definition(
                    label: 'Dismiss',
                    description: 'Dismiss the report and notify the original reporter.',
                ),
            ],
            'triaged' => [
                'resolve' => self::definition(
                    label: 'Resolve',
                    description: 'Mark the triaged report as resolved and notify the original reporter.',
                ),
                'dismiss' => self::definition(
                    label: 'Dismiss',
                    description: 'Dismiss the triaged report and notify the original reporter.',
                ),
                'reopen' => self::definition(
                    label: 'Reopen',
                    description: 'Return the triaged report to the open queue.',
                ),
            ],
            'resolved', 'dismissed' => [
                'reopen' => self::definition(
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
    private static function definition(string $label, string $description, bool $requiresResolutionNote = false): array
    {
        return [
            'label' => $label,
            'description' => $description,
            'requires_resolution_note' => $requiresResolutionNote,
        ];
    }
}
