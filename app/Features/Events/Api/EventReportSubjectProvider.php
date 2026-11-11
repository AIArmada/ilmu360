<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

use App\Shared\Reports\ReportSubjectResolver;

/**
 * Events-owned report subject provider.
 *
 * Reports composes this provider with Directory's provider. The interim
 * implementation reads through the event morph alias until the event model
 * moves into the feature.
 */
interface EventReportSubjectProvider extends ReportSubjectResolver {}
