<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Cohort projection for one committed outbound share.
 *
 * Written ONLY by the committed-share Signals reader as the final
 * effect of a `dawah.committed_share` signal — never directly by
 * web/API callers. Keyed by the signal's semantic key
 * (`committed-share:{touchpoint_id}`). Stage moves forward
 * (observed → participated) as outcome evidence settles; rows are
 * never deleted. Coarse by design: cohort week + outcome + stage,
 * with no actor ids, URLs, or subject-level detail.
 */
class ShareCohortProjection extends Model
{
    use HasUuids;

    public const string STAGE_OBSERVED = 'observed';

    public const string STAGE_PARTICIPATED = 'participated';

    protected $fillable = [
        'business_key',
        'outcome',
        'cohort_week',
        'stage',
        'occurred_at',
        'projected_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'projected_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (ShareCohortProjection $projection): void {
            foreach (['business_key', 'outcome', 'cohort_week', 'occurred_at'] as $field) {
                if ($projection->isDirty($field)) {
                    throw new LogicException("Share cohort projection [{$field}] is frozen.");
                }
            }

            if ($projection->isDirty('stage')
                && ! ($projection->getOriginal('stage') === self::STAGE_OBSERVED
                    && $projection->stage === self::STAGE_PARTICIPATED)
            ) {
                throw new LogicException('Share cohort projection stages move observed -> participated only.');
            }
        });

        static::deleting(static function (): void {
            throw new LogicException('Share cohort projections cannot be deleted.');
        });
    }
}
