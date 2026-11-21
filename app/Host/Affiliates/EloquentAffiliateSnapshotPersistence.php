<?php

declare(strict_types=1);

namespace App\Host\Affiliates;

use AIArmada\Affiliates\Models\Affiliate;
use AIArmada\Affiliates\Models\AffiliateAttribution;
use AIArmada\Affiliates\Models\AffiliateConversion;
use AIArmada\Affiliates\Models\AffiliateLink;
use AIArmada\Affiliates\Models\AffiliateTouchpoint;
use App\Shared\Contracts\AffiliateSnapshotPersistence;
use InvalidArgumentException;

final readonly class EloquentAffiliateSnapshotPersistence implements AffiliateSnapshotPersistence
{
    public function restoreProfile(array $attributes): string
    {
        $affiliate = new Affiliate;
        $affiliate->timestamps = false;
        $affiliate->setRawAttributes($attributes, true);
        $affiliate->saveQuietly();

        return (string) $affiliate->getKey();
    }

    public function restoreChild(string $kind, array $attributes): void
    {
        $model = match ($kind) {
            'link' => new AffiliateLink,
            'attribution' => new AffiliateAttribution,
            'touchpoint' => new AffiliateTouchpoint,
            'conversion' => new AffiliateConversion,
            default => throw new InvalidArgumentException('Unsupported affiliate snapshot record type.'),
        };
        $model->timestamps = false;
        $model->setRawAttributes($attributes, true);
        $model->saveQuietly();
    }
}
