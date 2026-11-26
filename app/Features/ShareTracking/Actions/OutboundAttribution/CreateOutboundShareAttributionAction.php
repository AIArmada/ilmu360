<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Actions\OutboundAttribution;

use AIArmada\Affiliates\Features\Attribution\Models\AffiliateAttribution;
use App\Features\ShareTracking\Api\CreateOutboundShareAttributionData;
use App\Features\ShareTracking\Api\OutboundShareAttributionReceipt;
use App\Features\ShareTracking\Api\OutboundShareAttributionWriter;

/**
 * Creates or reuses an outbound-share attribution without exposing its model.
 * The caller owns the surrounding transaction and link-lock order.
 */
final readonly class CreateOutboundShareAttributionAction implements OutboundShareAttributionWriter
{
    public function createOutboundShareAttribution(CreateOutboundShareAttributionData $data): OutboundShareAttributionReceipt
    {
        $attribution = AffiliateAttribution::query()->firstOrCreate(
            [
                'affiliate_id' => $data->affiliateId,
                'cookie_value' => null,
                'subject_type' => $data->subjectType,
                'subject_key' => $data->lookupSubjectKey,
                'subject_id' => $data->subjectId,
                'subject_instance' => $data->subjectInstance,
            ],
            [
                'affiliate_code' => $data->affiliateCode,
                'user_id' => $data->userId,
                'affiliate_link_id' => $data->affiliateLinkId,
                'subject_type' => $data->subjectType,
                'subject_id' => $data->subjectId,
                'subject_instance' => $data->subjectInstance,
                'subject_key' => $data->subjectKey,
                'origin' => $data->origin,
                'subject_title_snapshot' => $data->subjectTitleSnapshot,
                'attribution_type' => 'outbound_share',
                'channel' => $data->channel,
                'sharer_user_id' => $data->userId,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ],
        );

        return new OutboundShareAttributionReceipt(
            attributionId: (string) $attribution->getKey(),
            visitorKey: $attribution->visitor_key === null ? null : (string) $attribution->visitor_key,
            origin: $attribution->origin === null ? null : (string) $attribution->origin,
        );
    }
}
