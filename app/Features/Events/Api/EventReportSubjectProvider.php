<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

use App\Shared\Reports\ReportSubjectResolver;

/**
 * Events-owned report subject provider.
 *
 * Reports composes this provider with Directory's provider through the shared
 * report-subject contract.
 */
interface EventReportSubjectProvider extends ReportSubjectResolver {}
