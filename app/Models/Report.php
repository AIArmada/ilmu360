<?php

namespace App\Models;

use AIArmada\CommerceSupport\Models\Report as BaseReport;
use App\Models\Concerns\AuditsModelChanges;
use Database\Factories\ReportFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Report extends BaseReport implements AuditableContract, HasMedia
{
    /** @use HasFactory<ReportFactory> */
    use AuditsModelChanges, HasFactory, HasUuids, InteractsWithMedia;

    protected $appends = [
        'category',
        'description',
        'resolution_note',
    ];

    protected $fillable = [
        'reporter_id', 'reporter_type', 'reporter_fingerprint',
        'handled_by',
        'entity_type', 'entity_id',
        'report_type', 'category', 'message', 'description', 'title',
        'status', 'severity',
        'resolution', 'resolution_note',
        'reviewed_by_type', 'reviewed_by_id',
        'reported_at', 'reviewed_at', 'resolved_at', 'rejected_at', 'archived_at',
        'internal_notes', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'reported_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
        ];
    }

    public function getTable(): string
    {
        return 'reports';
    }

    public function getCategoryAttribute(?string $value): ?string
    {
        return $value ?? $this->report_type;
    }

    public function setCategoryAttribute(?string $value): void
    {
        $this->report_type = $value;
    }

    public function getDescriptionAttribute(?string $value): ?string
    {
        return $value ?? $this->message;
    }

    public function setDescriptionAttribute(?string $value): void
    {
        $this->message = $value;
    }

    public function getResolutionNoteAttribute(?string $value): ?string
    {
        return $value ?? $this->resolution;
    }

    public function setResolutionNoteAttribute(?string $value): void
    {
        $this->resolution = $value;
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function reportable(): MorphTo
    {
        return $this->morphTo(null, 'entity_type', 'entity_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function entity(): MorphTo
    {
        return $this->morphTo('entity');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
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

    protected static function booted(): void
    {
        static::creating(function (self $report) {
            $report->reporter_type ??= (new User)->getMorphClass();
        });
    }
}
