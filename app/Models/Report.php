<?php

namespace App\Models;

use AIArmada\CommerceSupport\Models\Report as BaseReport;
use App\Models\Concerns\AuditsModelChanges;
use Carbon\CarbonImmutable;
use Database\Factories\ReportFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use InvalidArgumentException;
use LogicException;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Report extends BaseReport implements AuditableContract, HasMedia
{
    /** @use HasFactory<ReportFactory> */
    use AuditsModelChanges, HasFactory, HasUuids, InteractsWithMedia;

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
        if ($this->exists) {
            throw new LogicException('An existing report must use transitionStatus().');
        }

        if ($status !== self::STATUS_OPEN) {
            throw new InvalidArgumentException('A new report must start in the open status.');
        }

        $now = CarbonImmutable::now();

        $this->setAttribute('status', $status);
        $this->applyStatusTimestamp($status, $now, true);
        $this->last_state_change_at ??= $now;

        return $this;
    }

    /**
     * Transition the report and record the matching lifecycle timestamp.
     */
    public function transitionStatus(string $status): static
    {
        if (! $this->exists) {
            throw new LogicException('A new report must use initializeStatus().');
        }

        $this->assertValidStatus($status);
        $currentStatus = $this->normalizeStatus($this->getAttribute('status'));

        if ($currentStatus === null) {
            throw new LogicException('A persisted report must have a valid status before it can transition.');
        }

        if ($currentStatus === $status) {
            $now = CarbonImmutable::now();
            $this->applyStatusTimestamp($status, $now);
            $this->last_state_change_at ??= $now;

            if ($this->isDirty()) {
                $this->save();
            }

            return $this;
        }

        $now = CarbonImmutable::now();
        $this->setAttribute('status', $status);
        $this->applyStatusTimestamp($status, $now, true);
        $this->last_state_change_at = $now;
        $this->save();

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
        $this->addMediaCollection('evidence')
            ->useDisk(config('media-library.disk_name'))
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf']);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->performOnCollections('evidence')
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

            $report->assertValidStatus((string) $report->getAttribute('status'));
            $now = CarbonImmutable::now();
            $report->applyStatusTimestamp((string) $report->getAttribute('status'), $now);
            $report->last_state_change_at ??= $now;
        });
    }

    private function assertValidStatus(string $status): void
    {
        if (! in_array($status, self::validStatuses(), true)) {
            throw new InvalidArgumentException(sprintf('The report status [%s] is invalid.', $status));
        }
    }

    /**
     * @return list<string>
     */
    private static function validStatuses(): array
    {
        return [
            self::STATUS_OPEN,
            self::STATUS_TRIAGED,
            self::STATUS_RESOLVED,
            self::STATUS_DISMISSED,
        ];
    }

    private function normalizeStatus(mixed $status): ?string
    {
        return is_string($status) && in_array($status, self::validStatuses(), true) ? $status : null;
    }

    private function applyStatusTimestamp(string $status, CarbonImmutable $at, bool $overwrite = false): void
    {
        $attribute = match ($status) {
            self::STATUS_OPEN => 'reported_at',
            self::STATUS_TRIAGED => 'reviewed_at',
            self::STATUS_RESOLVED => 'resolved_at',
            self::STATUS_DISMISSED => 'rejected_at',
            default => null,
        };

        if ($attribute === null) {
            return;
        }

        if ($overwrite || $this->getAttribute($attribute) === null) {
            $this->setAttribute($attribute, $at);
        }
    }
}
