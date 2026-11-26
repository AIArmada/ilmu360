<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Actions\LandingAttribution;

use AIArmada\Affiliates\Features\Attribution\Models\AffiliateAttribution;
use App\Features\ShareTracking\Api\CreateLandingAttributionData;
use App\Features\ShareTracking\Api\LandingAttributionReceipt;
use App\Features\ShareTracking\Api\LandingAttributionWriter;
use Carbon\CarbonInterface;

/**
 * Creates package-owned landing attribution rows without exposing the model.
 * The caller owns the surrounding transaction and browser-selection ordering.
 */
final readonly class CreateLandingAttributionAction implements LandingAttributionWriter
{
    public function createLandingAttribution(CreateLandingAttributionData $data): LandingAttributionReceipt
    {
        $attribution = new AffiliateAttribution([
            'affiliate_id' => $data->affiliateId,
            'affiliate_code' => $data->affiliateCode,
            'subject_type' => $data->subjectType,
            'subject_key' => $data->subjectKey,
            'subject_id' => $data->subjectId,
            'subject_instance' => 'default',
            'subject_title_snapshot' => $data->subjectTitleSnapshot,
            'cookie_value' => $data->cookieValue,
            'landing_url' => $data->landingUrl,
            'referrer_url' => null,
            'user_agent' => null,
            'ip_address' => $data->ipAddressHash,
            'user_id' => null,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'last_cookie_seen_at' => now(),
            'expires_at' => $this->expiryTimestamp(),
            'affiliate_link_id' => $data->affiliateLinkId,
            'attribution_type' => 'landing',
            'visitor_key' => $data->visitorKey,
            'channel' => $data->channel,
            'origin' => $data->origin,
            'sharer_user_id' => $data->sharerUserId,
            'metadata' => $data->campaignMetadata,
        ]);

        $attribution->owner_type = $data->ownerType;
        $attribution->owner_id = $data->ownerId;
        $attribution->save();

        return new LandingAttributionReceipt(
            attributionId: (string) $attribution->getKey(),
            cookieValue: (string) $attribution->cookie_value,
            affiliateId: (string) $attribution->affiliate_id,
            affiliateCode: (string) $attribution->affiliate_code,
            source: $attribution->source,
            medium: $attribution->medium,
            campaign: $attribution->campaign,
            term: $attribution->term,
            content: $attribution->content,
            subjectTitleSnapshot: $attribution->subject_title_snapshot,
            affiliateLinkId: $attribution->affiliate_link_id,
            visitorKey: $attribution->visitor_key,
            channel: $attribution->channel,
            origin: $attribution->origin,
        );
    }

    private function expiryTimestamp(): ?CarbonInterface
    {
        $ttlDays = (int) config('share-tracking.ttl_days', 30);

        return $ttlDays > 0 ? now()->addDays($ttlDays) : null;
    }
}
