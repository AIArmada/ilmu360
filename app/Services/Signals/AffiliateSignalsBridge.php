<?php

declare(strict_types=1);

namespace App\Services\Signals;

use AIArmada\Affiliates\Models\AffiliateAttribution;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Signals\Models\TrackedProperty;

class AffiliateSignalsBridge
{
    public function __construct(
        private readonly SignalEventRecorder $signalEventRecorder,
        private readonly SignalsTracker $signalsTracker,
    ) {}

    public function recordAffiliateAttributed(AffiliateAttribution $attribution): void
    {
        $trackedProperty = $this->signalsTracker->trackedPropertyForSurface('public');

        if (! $trackedProperty instanceof TrackedProperty) {
            return;
        }

        $cartIdentifier = $this->stringValue($attribution->cart_identifier)
            ?? $this->stringValue($attribution->subject_identifier)
            ?? $this->stringValue($attribution->cookie_value);
        $cartInstance = $this->stringValue($attribution->cart_instance)
            ?? $this->stringValue($attribution->subject_instance)
            ?? 'default';
        $landingUrl = $this->stringValue($attribution->landing_url);

        OwnerContext::withOwner(null, fn () => $this->signalEventRecorder->ingest($trackedProperty, [
            'event_name' => (string) config('signals.integrations.affiliates.attributed_event_name', 'affiliate.attributed'),
            'event_category' => (string) config('signals.integrations.affiliates.attributed_event_category', 'acquisition'),
            'external_id' => $this->stringValue($attribution->user_id),
            'anonymous_id' => $cartIdentifier,
            'session_identifier' => $this->affiliateSessionIdentifier($cartIdentifier, $cartInstance),
            'occurred_at' => $attribution->last_seen_at?->toIso8601String() ?? $attribution->created_at?->toIso8601String(),
            'path' => $landingUrl,
            'url' => $landingUrl,
            'referrer' => $this->stringValue($attribution->referrer_url),
            'source' => $this->stringValue($attribution->source),
            'medium' => $this->stringValue($attribution->medium),
            'campaign' => $this->stringValue($attribution->campaign),
            'properties' => array_filter([
                'attribution_id' => $this->stringValue($attribution->getKey()),
                'affiliate_id' => $this->stringValue($attribution->affiliate_id),
                'affiliate_code' => $this->stringValue($attribution->affiliate_code),
                'subject_type' => $this->stringValue($attribution->subject_type),
                'subject_identifier' => $this->stringValue($attribution->subject_identifier),
                'subject_instance' => $this->stringValue($attribution->subject_instance),
                'subject_title_snapshot' => $this->stringValue($attribution->subject_title_snapshot),
                'cart_identifier' => $this->stringValue($attribution->cart_identifier),
                'cart_instance' => $this->stringValue($attribution->cart_instance),
                'cookie_value' => $this->stringValue($attribution->cookie_value),
                'voucher_code' => $this->stringValue($attribution->voucher_code),
                'landing_url' => $landingUrl,
            ], static fn (mixed $value): bool => $value !== null),
        ]));
    }

    public function recordAffiliateConversionRecorded(AffiliateConversion $conversion): void
    {
        $trackedProperty = $this->signalsTracker->trackedPropertyForSurface('public');

        if (! $trackedProperty instanceof TrackedProperty) {
            return;
        }

        $cartIdentifier = $this->stringValue($conversion->cart_identifier)
            ?? $this->stringValue($conversion->subject_identifier)
            ?? $this->stringValue(data_get($conversion->metadata, 'cookie_value'));
        $cartInstance = $this->stringValue($conversion->cart_instance)
            ?? $this->stringValue($conversion->subject_instance)
            ?? 'default';
        $destinationUrl = $this->stringValue(data_get($conversion->metadata, 'destination_url'));

        OwnerContext::withOwner(null, fn () => $this->signalEventRecorder->ingest($trackedProperty, [
            'event_name' => (string) config('signals.integrations.affiliates.conversion_event_name', 'affiliate.conversion.recorded'),
            'event_category' => (string) config('signals.integrations.affiliates.conversion_event_category', 'conversion'),
            'external_id' => $this->stringValue(data_get($conversion->metadata, 'user_id')),
            'anonymous_id' => $cartIdentifier,
            'session_identifier' => $this->affiliateSessionIdentifier($cartIdentifier, $cartInstance),
            'occurred_at' => $conversion->occurred_at?->toIso8601String() ?? $conversion->created_at?->toIso8601String(),
            'path' => $destinationUrl,
            'url' => $destinationUrl,
            'revenue_minor' => (int) ($conversion->value_minor ?: $conversion->total_minor ?: 0),
            'currency' => $this->stringValue($conversion->commission_currency) ?? (string) config('signals.defaults.currency', 'MYR'),
            'properties' => array_filter([
                'conversion_id' => $this->stringValue($conversion->getKey()),
                'affiliate_id' => $this->stringValue($conversion->affiliate_id),
                'affiliate_code' => $this->stringValue($conversion->affiliate_code),
                'affiliate_attribution_id' => $this->stringValue($conversion->affiliate_attribution_id),
                'conversion_type' => $this->stringValue($conversion->conversion_type),
                'subject_type' => $this->stringValue($conversion->subject_type),
                'subject_identifier' => $this->stringValue($conversion->subject_identifier),
                'subject_instance' => $this->stringValue($conversion->subject_instance),
                'subject_title_snapshot' => $this->stringValue($conversion->subject_title_snapshot),
                'cart_identifier' => $this->stringValue($conversion->cart_identifier),
                'cart_instance' => $this->stringValue($conversion->cart_instance),
                'external_reference' => $this->stringValue($conversion->external_reference),
                'order_reference' => $this->stringValue($conversion->order_reference),
                'voucher_code' => $this->stringValue($conversion->voucher_code),
                'status' => $this->stringValue((string) $conversion->status),
            ], static fn (mixed $value): bool => $value !== null),
        ]));
    }

    private function affiliateSessionIdentifier(?string $identifier, string $instance): ?string
    {
        if ($identifier === null || $identifier === '') {
            return null;
        }

        return 'affiliate:'.$identifier.':'.$instance;
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
