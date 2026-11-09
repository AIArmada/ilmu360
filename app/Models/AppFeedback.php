<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AppFeedbackCategory;
use App\Enums\AppFeedbackGitHubStatus;
use App\Enums\AppFeedbackStatus;
use App\Platform\Media\MediaEvidence;
use Carbon\CarbonImmutable;
use Database\Factories\AppFeedbackFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\DeletedModels\Models\Concerns\KeepsDeletedModels;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @property string $id
 * @property string $reporter_id
 * @property string $submission_key
 * @property string $payload_hash
 * @property AppFeedbackCategory $category
 * @property string $title
 * @property string $description
 * @property string|null $page_url
 * @property array<string, mixed>|null $diagnostics
 * @property AppFeedbackStatus $status
 * @property string|null $handled_by
 * @property string|null $internal_notes
 * @property string|null $resolution_note
 * @property CarbonImmutable|null $triaged_at
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $declined_at
 * @property CarbonImmutable $last_state_change_at
 * @property AppFeedbackGitHubStatus $github_escalation_status
 * @property string|null $github_escalation_token
 * @property CarbonImmutable|null $github_requested_at
 * @property CarbonImmutable|null $github_created_at
 * @property CarbonImmutable|null $github_unknown_at
 * @property int|null $github_issue_number
 * @property string|null $github_issue_url
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User|null $reporter
 * @property-read User|null $handler
 * @property-read Collection<int, Media> $media
 */
class AppFeedback extends Model implements HasMedia
{
    /** @use HasFactory<AppFeedbackFactory> */
    use HasFactory;

    use HasUuids;
    use InteractsWithMedia;
    use KeepsDeletedModels;

    protected $table = 'app_feedback';

    protected $fillable = [
        'category',
        'title',
        'description',
        'page_url',
        'diagnostics',
        'details',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'submission_key',
        'payload_hash',
        'internal_notes',
        'diagnostics',
        'github_escalation_token',
        'github_issue_number',
        'github_issue_url',
        'media',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => AppFeedbackCategory::class,
            'status' => AppFeedbackStatus::class,
            'github_escalation_status' => AppFeedbackGitHubStatus::class,
            'diagnostics' => 'array',
            'details' => 'array',
            'triaged_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'declined_at' => 'immutable_datetime',
            'last_state_change_at' => 'immutable_datetime',
            'github_requested_at' => 'immutable_datetime',
            'github_created_at' => 'immutable_datetime',
            'github_unknown_at' => 'immutable_datetime',
            'github_issue_number' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    /**
     * @return HasMany<AppFeedbackMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(AppFeedbackMessage::class, 'feedback_id')->latest('created_at');
    }

    /**
     * @return HasMany<AppFeedbackMessage, $this>
     */
    public function visibleMessages(): HasMany
    {
        return $this->hasMany(AppFeedbackMessage::class, 'feedback_id')
            ->where('visibility', 'reporter')
            ->oldest('created_at');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(MediaEvidence::COLLECTION)
            ->useDisk(MediaEvidence::PRIVATE_DISK)
            ->acceptsMimeTypes(MediaEvidence::mimeTypes());
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion(MediaEvidence::THUMB_CONVERSION)
            ->performOnCollections(MediaEvidence::COLLECTION)
            ->fit(Fit::Max, 1080, 1080)
            ->sharpen(10)
            ->format('webp');
    }
}
