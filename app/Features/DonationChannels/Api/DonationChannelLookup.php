<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\Api;

use App\Features\Reports\Api\ReportSubject;

interface DonationChannelLookup
{
    public function findReportSubject(string $id): ?ReportSubject;

    public function resolveReportSubject(string $identifier): ?ReportSubject;
}
