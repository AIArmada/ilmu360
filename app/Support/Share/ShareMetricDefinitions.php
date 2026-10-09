<?php

declare(strict_types=1);

namespace App\Support\Share;

/**
 * Named share metric units.
 *
 * Every reporting surface names the canonical measurement definition
 * version it used (see ShareTrackingMeasurement), so a later definition
 * change never silently rewrites history. Units are honest named units:
 * browsers, shares, participations — never people, never uplift. Stages
 * distinguish submitted (observed) from recorded (committed outbound
 * share) from accepted (currently valid) from verified (settled/paid).
 * Intensity (shares per browser) is never conversion; fact counts are
 * never cash, points, or paid value.
 */
final class ShareMetricDefinitions
{
    public const string VERSION = 'v1';

    /**
     * @return array<string, array{unit: string, description: string}>
     */
    public static function all(): array
    {
        return [
            'submitted_shares' => [
                'unit' => 'shares',
                'description' => 'Issued share tracking links (share payloads prepared), including links never acted on.',
            ],
            'recorded_shares' => [
                'unit' => 'shares',
                'description' => 'Committed outbound share actions (outbound_share touchpoints), the durable share truth. Never downstream outcomes.',
            ],
            'recorded_visits' => [
                'unit' => 'visits',
                'description' => 'Committed downstream visit touchpoints on share links.',
            ],
            'distinct_sharing_browsers' => [
                'unit' => 'browsers',
                'description' => 'Distinct frozen browser keys (visitor_key historical union over landing attributions and visit touchpoints). Browsers are not people; later landings never rewrite history.',
            ],
            'share_intensity' => [
                'unit' => 'shares_per_browser',
                'description' => 'Recorded shares per distinct sharing browser. Intensity, not conversion.',
            ],
            'accepted_participations' => [
                'unit' => 'participations',
                'description' => 'Currently valid conversions (qualified, approved, or paid). Pending, rejected, and reversed outcomes are reported separately, never mixed in.',
            ],
            'verified_participations' => [
                'unit' => 'participations',
                'description' => 'Paid conversions: settled value. A subset of accepted participations, never a relabel of it.',
            ],
            'first_share_conversion' => [
                'unit' => 'ratio',
                'description' => 'Mature cohort signups with a first committed outbound share inside their own 30-day window, over the mature cohort denominator. Immature cohorts report pending, never rates.',
            ],
            'participation_conversion' => [
                'unit' => 'ratio',
                'description' => 'Mature cohort signups with a first committed outbound share followed by accepted downstream participation inside their own 30-day window, over the mature cohort denominator. Fraud-evidenced sharers are excluded and listed under fraud.',
            ],
        ];
    }

    public static function unit(string $metric): string
    {
        return self::all()[$metric]['unit'] ?? 'unknown';
    }
}
