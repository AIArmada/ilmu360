<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Shared\Reports\ReportSubject;
use App\Shared\Reports\ReportSubjectResolver;
use Lorisleiva\Actions\Concerns\AsAction;

class ResolveReportFormContextAction
{
    use AsAction;

    public function __construct(
        protected ResolveReportCategoryOptionsAction $resolveReportCategoryOptionsAction,
        protected ReportSubjectResolver $reportSubjects,
    ) {}

    /**
     * @return array{
     *     subject_label: string,
     *     subject_title: string,
     *     category_options: array<string, string>,
     *     redirect_url: string,
     *     default_category: string,
     *     profile_image_url: string|null
     * }
     */
    public function handle(ReportSubject $subject): array
    {
        $presentation = $this->reportSubjects->presentation($subject);
        $categoryOptions = $this->resolveReportCategoryOptionsAction->handle($subject->type->value);

        return [
            'subject_label' => $presentation->subjectLabel,
            'subject_title' => $presentation->subjectTitle,
            'category_options' => $categoryOptions,
            'redirect_url' => $presentation->redirectUrl,
            'default_category' => (string) array_key_first($categoryOptions),
            'profile_image_url' => $presentation->profileImageUrl,
        ];
    }
}
