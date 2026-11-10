<?php

declare(strict_types=1);

namespace App\Features\Directory\Api;

use App\Shared\Reports\ReportSubjectResolver;

/**
 * Directory-owned report subject provider.
 *
 * Reports composes this provider with the interim Events provider until Events
 * exposes its own public subject API.
 */
interface DirectoryReportSubjectProvider extends ReportSubjectResolver {}
