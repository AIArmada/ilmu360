<?php

namespace App\Models;

use AIArmada\Membership\Enums\ApplicationStatus;
use App\Enums\MemberSubjectType;
use App\Models\Concerns\AuditsModelChanges;
use Database\Factories\MembershipClaimFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MembershipClaim extends Model implements AuditableContract, HasMedia
{
    /** @use HasFactory<MembershipClaimFactory> */
    use AuditsModelChanges, HasFactory, HasUuids, InteractsWithMedia;

    public $incrementing = false;

    protected $keyType = 'string';

    public function getTable(): string
    {
        return config('membership.database.tables.applications', 'membership_applications');
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'subject_type',
        'subject_id',
        'applicant_id',
        'reviewer_id',
        'status',
        'granted_role',
        'justification',
        'reviewer_note',
        'reviewed_at',
        'cancelled_at',
        'meta',
    ];

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'subject_type' => MemberSubjectType::class,
            'status' => ApplicationStatus::class,
            'meta' => 'array',
            'reviewed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->where('status', ApplicationStatus::Pending);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applicant_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function isPending(): bool
    {
        return $this->status === ApplicationStatus::Pending;
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('evidence')
            ->useDisk(config('media-library.disk_name'))
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf']);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->performOnCollections('evidence')
            ->width(200)
            ->height(200)
            ->format('webp');
    }
}
