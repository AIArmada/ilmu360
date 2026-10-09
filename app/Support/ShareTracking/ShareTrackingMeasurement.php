<?php

declare(strict_types=1);

namespace App\Support\ShareTracking;

/**
 * Canonical share-measurement contract.
 *
 * Until the operator declares a reporting start, no certified numbers are
 * presented under the current definition: readers withhold measurements
 * and report a not-configured status. There is no runtime now() fallback
 * and no historical old-schema reader or backfill.
 */
final class ShareTrackingMeasurement
{
    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_NOT_CONFIGURED = 'not_configured';

    public static function definitionVersion(): string
    {
        return (string) config('dawah-share.reporting.definition_version', 'wp1');
    }

    public static function reportingStart(): ?string
    {
        $reportingStart = config('dawah-share.reporting.reporting_start');

        return is_string($reportingStart) && trim($reportingStart) !== '' ? trim($reportingStart) : null;
    }

    public static function isConfigured(): bool
    {
        return self::reportingStart() !== null;
    }

    public static function status(): string
    {
        return self::isConfigured() ? self::STATUS_ACTIVE : self::STATUS_NOT_CONFIGURED;
    }
}
