<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Actions\Contributions\ResolveContributionSubjectPresentationAction;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Speaker;
use Lorisleiva\Actions\Concerns\AsAction;

class ResolveReportFormContextAction
{
    use AsAction;

    public function __construct(
        protected ResolveReportCategoryOptionsAction $resolveReportCategoryOptionsAction,
        protected ResolveContributionSubjectPresentationAction $resolveContributionSubjectPresentationAction,
    ) {}

    /**
     * @return array{
     *     subject_label: string,
     *     subject_title: string,
     *     category_options: array<string, string>,
     *     redirect_url: string,
     *     default_category: string
     * }
     */
    public function handle(string $subjectType, Event|Institution|Reference|Speaker $entity): array
    {
        $presentation = $this->resolveContributionSubjectPresentationAction->handle($entity);
        $categoryOptions = $this->resolveReportCategoryOptionsAction->handle($subjectType);

        return [
            'subject_label' => $presentation['subject_label'],
            'subject_title' => $presentation['subject_title'],
            'category_options' => $categoryOptions,
            'redirect_url' => $presentation['redirect_url'],
            'default_category' => (string) array_key_first($categoryOptions),
        ];
    }
}
