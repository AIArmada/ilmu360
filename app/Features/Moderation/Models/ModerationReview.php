<?php

namespace App\Features\Moderation\Models;

use AIArmada\Moderation\Models\ModerationAction;
use App\Models\User;
use App\Platform\Auditing\AuditsModelChanges;
use Database\Factories\ModerationReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * App wrapper around package moderation actions used by moderation transitions
 * and account-deletion cleanup.
 */
class ModerationReview extends ModerationAction implements Auditable
{
    use AuditsModelChanges;

    #[\Override]
    protected $table = 'moderation_actions';

    /**
     * @var list<string>
     */
    #[\Override]
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
    #[Scope]
    protected function whereEventId(Builder $query, string $eventId): Builder
    {
        return $query
            ->where('actionable_id', $eventId)
            ->where('actionable_type', (new (Relation::getMorphedModel('event')))->getMorphClass());
    }

    /**
     * @return BelongsTo<\Illuminate\Database\Eloquent\Model, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo((string) Relation::getMorphedModel('event'), 'actionable_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actioned_by_id');
    }

    protected static function newFactory(): ModerationReviewFactory
    {
        return ModerationReviewFactory::new();
    }
}
