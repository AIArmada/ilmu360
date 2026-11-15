<?php

declare(strict_types=1);

namespace App\Features\Directory\Api;

use App\Shared\Reports\ReportSubjectResolver;

/**
 * Directory-owned report subject provider.
 *
 * Reports composes this provider with the Events-owned provider through the
 * shared report-subject contract.
 */
interface DirectoryReportSubjectProvider extends ReportSubjectResolver {}
