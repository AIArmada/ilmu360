<?php

namespace App\Models;

use App\Enums\ContributionRequestStatus;
use App\Enums\ContributionRequestType;
use App\Enums\ContributionSubjectType;
use App\Models\Concerns\AuditsModelChanges;
use Database\Factories\ContributionRequestFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class ContributionRequest extends Model implements AuditableContract
{
    /** @use HasFactory<ContributionRequestFactory> */
    use AuditsModelChanges, HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'subject_type',
        'entity_type',
        'entity_id',
        'proposer_id',
        'reviewer_id',
        'status',
        'reason_code',
        'proposer_note',
        'reviewer_note',
        'proposed_data',
        'original_data',
        'reviewed_at',
        'cancelled_at',
    ];

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'type' => ContributionRequestType::class,
            'subject_type' => ContributionSubjectType::class,
            'status' => ContributionRequestStatus::class,
            'proposed_data' => 'array',
            'original_data' => 'array',
            'reviewed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposer_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function entity(): MorphTo
    {
        return $this->morphTo();
    }

    public function isPending(): bool
    {
        return $this->status === ContributionRequestStatus::Pending;
    }
}
