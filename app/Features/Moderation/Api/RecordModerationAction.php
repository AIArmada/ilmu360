<?php

declare(strict_types=1);

namespace App\Features\Moderation\Api;

/**
 * Records a moderation action for an actionable owner record.
 *
 * The actionable owner passes its persisted morph type and key; Moderation
 * owns the storage write and returns immutable recorded values.
 */
interface RecordModerationAction
{
    /**
     * @param  array{
     *     actionable_type: string,
     *     actionable_id: string,
     *     actioned_by_type: string|null,
     *     actioned_by_id: string|null,
     *     type: string,
     *     reason: string|null,
     *     notes: string|null,
     *     metadata?: array<string, mixed>|null
     * }  $attributes
     */
    public function record(array $attributes): RecordedModerationAction;
}
