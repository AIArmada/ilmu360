<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Features\Reports\Api\ReportCommands;
use App\Models\Report;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class DeleteReportAction
{
    use AsAction;

    public function __construct(
        private ReportCommands $commands,
    ) {}

    public function handle(Report|string $report): void
    {
        $this->commands->delete($report instanceof Report ? (string) $report->getKey() : $report);
    }
}
