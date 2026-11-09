<?php

namespace App\Filament\Resources\Reports\Support;

use App\Models\Report;
use App\Shared\Reports\ReportSubject;
use App\Shared\Reports\ReportSubjectResolver;
use WeakMap;

class ReportPresenter
{
    /** @var WeakMap<Report, ReportSubject|null>|null */
    private static ?WeakMap $resolvedSubjects = null;

    public static function entityTitle(Report $report): string
    {
        $subject = self::resolve($report);

        return $subject->title ?? (string) $report->entity_id;
    }

    public static function entityAdminUrl(Report $report): ?string
    {
        return self::resolve($report)?->adminUrl;
    }

    private static function resolve(Report $report): ?ReportSubject
    {
        self::$resolvedSubjects ??= new WeakMap;

        if (self::$resolvedSubjects->offsetExists($report)) {
            return self::$resolvedSubjects[$report];
        }

        return self::$resolvedSubjects[$report] = app(ReportSubjectResolver::class)->find(
            (string) $report->entity_type,
            (string) $report->entity_id,
        );
    }
}
