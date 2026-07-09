<?php

namespace App\Models;

use AIArmada\Moderation\Models\ModerationAction;
use App\Models\Concerns\AuditsModelChanges;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * App wrapper around package moderation actions used by moderation transitions
 * and account-deletion cleanup.
 */
class ModerationReview extends ModerationAction implements Auditable
{
    use AuditsModelChanges;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'owner_type',
        'owner_id',
        'actionable_type',
        'actionable_id',
        'actioned_by_type',
        'actioned_by_id',
        'type',
        'reason',
        'notes',
        'metadata',
    ];

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWhereEventId(Builder $query, string $eventId): Builder
    {
        return $query
            ->where('actionable_id', $eventId)
            ->where(function (Builder $typeQuery): void {
                $typeQuery
                    ->where('actionable_type', Event::class)
                    ->orWhere('actionable_type', (new Event)->getMorphClass());
            });
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'actionable_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actioned_by_id');
    }
}
