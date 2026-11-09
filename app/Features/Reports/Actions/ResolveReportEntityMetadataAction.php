<?php

declare(strict_types=1);

namespace App\Features\Reports\Actions;

use App\Shared\Reports\ReportSubjectResolver;
use Lorisleiva\Actions\Concerns\AsAction;

class ResolveReportEntityMetadataAction
{
    use AsAction;

    public function __construct(
        private readonly ReportSubjectResolver $reportSubjects,
    ) {}

    /**
     * @return array{label: string}
     */
    public function handle(string $entityType): array
    {
        return [
            'label' => $this->reportSubjects->label($entityType),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function options(): array
    {
        $options = [];

        foreach ($this->validKeys() as $entityType) {
            $options[$entityType] = $this->handle($entityType)['label'];
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    public function validKeys(): array
    {
        return $this->reportSubjects->validKeys();
    }
}
