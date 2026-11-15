<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Actions\Touchpoints;

use AIArmada\Affiliates\Models\AffiliateTouchpoint;
use App\Features\ShareTracking\Api\OutboundShareTouchpointData;
use App\Features\ShareTracking\Api\OutboundShareTouchpointWriter;

/**
 * Writes the outbound-share touchpoint using the package model and table resolver.
 * The caller owns the surrounding transaction and link lock order.
 */
final readonly class RecordOutboundShareTouchpointAction implements OutboundShareTouchpointWriter
{
    public function recordOutboundShare(OutboundShareTouchpointData $data): void
    {
        AffiliateTouchpoint::query()->create([
            'affiliate_attribution_id' => $data->attributionId,
            'affiliate_id' => $data->affiliateId,
            'affiliate_code' => $data->affiliateCode,
            'ip_address' => $data->ipAddressHash,
            'user_agent' => null,
            'touchpoint_type' => 'outbound_share',
            'affiliate_link_id' => $data->linkId,
            'subject_type' => $data->subjectType,
            'subject_key' => $data->subjectKey,
            'subject_id' => $data->subjectId,
            'subject_instance' => $data->subjectInstance,
            'subject_title_snapshot' => $data->subjectTitleSnapshot,
            'visitor_key' => $data->visitorKey,
            'channel' => $data->channel,
            'origin' => $data->origin,
            'referrer_url' => null,
            'touched_at' => now()->utc(),
        ]);
    }
}
