<?php

declare(strict_types=1);

namespace App\Features\Reports\Actions;

use App\Features\Reports\Api\ReportCommands;
use Lorisleiva\Actions\Concerns\AsAction;

final readonly class DeleteReportsAction
{
    use AsAction;

    public function __construct(
        private ReportCommands $commands,
    ) {}

    /**
     * @param  array<int, int|string>  $reportIds
     */
    public function handle(array $reportIds): void
    {
        foreach ($reportIds as $reportId) {
            $this->commands->delete((string) $reportId);
        }
    }
}
