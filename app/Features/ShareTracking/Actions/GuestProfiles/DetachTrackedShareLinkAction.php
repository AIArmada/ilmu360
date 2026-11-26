<?php

declare(strict_types=1);

namespace App\Features\ShareTracking\Actions\GuestProfiles;

use AIArmada\Affiliates\Features\Attribution\Models\AffiliateLink;
use AIArmada\Links\Actions\UpdateLink;
use AIArmada\Links\Models\Link;
use App\Features\ShareTracking\Api\TrackedShareLinkDetacher;

final readonly class DetachTrackedShareLinkAction implements TrackedShareLinkDetacher
{
    /**
     * Keep the public destination while removing private identity and stopping
     * the vendor delete cascade from removing this short redirect.
     */
    public function detachTrackedShareLink(string $affiliateLinkId): void
    {
        $link = new AffiliateLink;
        $link->setAttribute($link->getKeyName(), $affiliateLinkId);

        $tracked = $link->trackedLink()->first();

        if (! $tracked instanceof Link) {
            return;
        }

        UpdateLink::run($tracked, [
            'name' => 'Detached share redirect',
            'parameters' => [],
            'subject_id' => '00000000-0000-0000-0000-000000000000',
        ]);
    }
}
