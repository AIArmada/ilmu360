<?php

namespace App\Models;

use AIArmada\Contacting\Concerns\HasContactMethods;
use App\Models\Concerns\AuditsModelChanges;
use App\Models\Concerns\HasPackageContactAliases;
use Database\Factories\EventSubmissionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * @property array<string, mixed>|null $submission_data
 * @property string|null $submitter_name
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property string|null $submitted_by
 */
class EventSubmission extends Model implements AuditableContract
{
    /** @use HasFactory<EventSubmissionFactory> */
    use AuditsModelChanges, HasContactMethods, HasFactory, HasPackageContactAliases, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'submitter_type',
        'submitter_id',
        'target_type',
        'target_id',
        'event_id',
        'event_occurrence_id',
        'submission_data',
        'status',
        'submitted_at',
        'reviewed_at',
        'metadata',
        'submitted_by',
        'submitter_name',
        'notes',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'submission_data' => 'array',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    #[\Override]
    public function setAttribute($key, $value): mixed
    {
        if ($key === 'submitted_by') {
            parent::setAttribute('submitter_type', $value === null ? null : User::class);

            return parent::setAttribute('submitter_id', $value);
        }

        if ($key === 'submitter_name' || $key === 'notes') {
            $submissionData = $this->submission_data;
            $submissionData = is_array($submissionData) ? $submissionData : [];
            $submissionData[$key] = $value;

            return parent::setAttribute('submission_data', $submissionData);
        }

        return parent::setAttribute($key, $value);
    }

    #[\Override]
    public function getAttribute($key): mixed
    {
        if ($key === 'submitted_by') {
            return parent::getAttribute('submitter_id');
        }

        if ($key === 'submitter_name' || $key === 'notes') {
            $submissionData = $this->submission_data;

            return is_array($submissionData) ? ($submissionData[$key] ?? null) : null;
        }

        return parent::getAttribute($key);
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitter_id');
    }
}
