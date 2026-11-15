<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Actions\Touchpoints;

use AIArmada\Affiliates\Models\AffiliateTouchpoint;
use App\Features\ShareTracking\Api\VisitTouchpointData;
use App\Features\ShareTracking\Api\VisitTouchpointWriter;

/**
 * Persists a visit touchpoint through the Affiliates package model so its
 * configured table resolver and owner context remain in effect.
 *
 * The caller owns the enclosing transaction, browser-selection lock order,
 * and visit-deduplication decision.
 */
final readonly class RecordVisitTouchpointAction implements VisitTouchpointWriter
{
    public function recordVisit(VisitTouchpointData $data): void
    {
        AffiliateTouchpoint::query()->create([
            'affiliate_attribution_id' => $data->attributionId,
            'affiliate_id' => $data->affiliateId,
            'affiliate_code' => $data->affiliateCode,
            'source' => $data->source,
            'medium' => $data->medium,
            'campaign' => $data->campaign,
            'term' => $data->term,
            'content' => $data->content,
            'ip_address' => $data->ipAddress,
            'user_agent' => null,
            'touchpoint_type' => 'visit',
            'metadata' => ['visit_kind' => $data->visitKind],
            'affiliate_link_id' => $data->linkId,
            'visitor_key' => $data->visitorKey,
            'channel' => $data->channel,
            'origin' => $data->origin,
            'url' => $data->url,
            'referrer_url' => null,
            'subject_type' => $data->subjectType,
            'subject_key' => $data->subjectKey,
            'subject_id' => $data->subjectId,
            'touched_at' => now(),
        ]);
    }
}
