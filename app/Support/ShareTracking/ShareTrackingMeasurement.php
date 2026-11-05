<?php

declare(strict_types=1);

namespace App\Support\ShareTracking;

use Carbon\CarbonImmutable;
use Throwable;

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
        return (string) config('share-tracking.reporting.definition_version', 'wp1');
    }

    public static function reportingStart(): ?string
    {
        $reportingStart = config('share-tracking.reporting.reporting_start');

        if (! is_string($reportingStart) || trim($reportingStart) === '') {
            return null;
        }

        $reportingStart = trim($reportingStart);

        try {
            if (CarbonImmutable::hasRelativeKeywords($reportingStart)) {
                return null;
            }

            CarbonImmutable::parse($reportingStart, 'UTC');
            $errors = CarbonImmutable::getLastErrors();

            if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        return $reportingStart;
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
