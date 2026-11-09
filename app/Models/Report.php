<?php

namespace App\Models;

use AIArmada\CommerceSupport\Models\Report as BaseReport;
use App\Platform\Auditing\AuditsModelChanges;
use App\Platform\Media\MediaEvidence;
use App\Platform\Reporting\ReportLifecycle;
use Database\Factories\ReportFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @property string $status Current lifecycle status: open, triaged, resolved, or dismissed.
 */
class Report extends BaseReport implements AuditableContract, HasMedia
{
    /** @use HasFactory<ReportFactory> */
    use AuditsModelChanges, HasFactory, HasUuids, InteractsWithMedia;

    protected $table = 'reports';

    public const string STATUS_OPEN = 'open';

    public const string STATUS_TRIAGED = 'triaged';

    public const string STATUS_RESOLVED = 'resolved';

    public const string STATUS_DISMISSED = 'dismissed';

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
        'severity',
        'resolution', 'resolution_note',
        'reviewed_by_type', 'reviewed_by_id',
        'internal_notes', 'metadata',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'reported_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
            'last_state_change_at' => 'immutable_datetime',
        ];
    }

    /**
     * Create an unsaved report in the only valid initial state.
     */
    public static function makeOpen(): self
    {
        $report = new self;
        $report->initializeStatus(self::STATUS_OPEN);

        return $report;
    }

    /**
     * Set the report's initial lifecycle state before persistence.
     */
    public function initializeStatus(string $status): static
    {
        app(ReportLifecycle::class)->initialize($this, $status);

        return $this;
    }

    /**
     * Transition the report and record the matching lifecycle timestamp.
     */
    public function transitionStatus(string $status): static
    {
        app(ReportLifecycle::class)->transition($this, $status);

        return $this;
    }

    #[\Override]
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
    #[\Override]
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
        $this->addMediaCollection(MediaEvidence::COLLECTION)
            ->useDisk(MediaEvidence::publicDisk())
            ->acceptsMimeTypes(MediaEvidence::mimeTypes());
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion(MediaEvidence::THUMB_CONVERSION)
            ->performOnCollections(MediaEvidence::COLLECTION)
            ->fit(Fit::Max, 1080, 1080)
            ->format('webp');
    }

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (self $report): void {
            $report->reporter_type ??= (new User)->getMorphClass();

            if ($report->getAttribute('status') === null) {
                $report->initializeStatus(self::STATUS_OPEN);

                return;
            }

            app(ReportLifecycle::class)->preparePersistedStatus($report, (string) $report->getAttribute('status'));
        });
    }
}
