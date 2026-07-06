<?php

namespace App\Models;

use AIArmada\Moderation\Models\ModerationAction;
use App\Models\Concerns\AuditsModelChanges;
use Database\Factories\ModerationReviewFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class ModerationReview extends ModerationAction implements AuditableContract
{
    use AuditsModelChanges;

    protected $fillable = [
        'actionable_type', 'actionable_id',
        'actioned_by_type', 'actioned_by_id',
        'type', 'reason', 'notes', 'metadata',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'actionable_id');
    }

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actioned_by_id');
    }

    protected static function newFactory(): ModerationReviewFactory
    {
        return ModerationReviewFactory::new();
    }
}
