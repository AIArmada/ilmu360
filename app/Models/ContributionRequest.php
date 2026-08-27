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
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ContributionRequest extends Model implements AuditableContract, HasMedia
{
    /** @use HasFactory<ContributionRequestFactory> */
    use AuditsModelChanges, HasFactory, HasUuids, InteractsWithMedia;

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
        'approved_at',
        'rejected_at',
        'cancelled_at',
        'last_state_change_at',
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
            'reviewed_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'last_state_change_at' => 'immutable_datetime',
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

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('pending_media');
    }

    /**
     * @return MediaCollection<int, Media>
     */
    public function getPendingMedia(): MediaCollection
    {
        return $this->getMedia('pending_media');
    }
}
