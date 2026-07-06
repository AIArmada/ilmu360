<?php

namespace App\Models;

use AIArmada\Contacting\Concerns\HasContactMethods;
use AIArmada\Events\Models\EventSubmission as PackageEventSubmission;
use App\Models\Concerns\AuditsModelChanges;
use App\Models\Concerns\HasPackageContactAliases;
use Database\Factories\EventSubmissionFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class EventSubmission extends PackageEventSubmission implements AuditableContract
{
    use AuditsModelChanges, HasContactMethods, HasPackageContactAliases;

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
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'submission_data' => 'array',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'metadata' => 'array',
        ]);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    protected static function newFactory(): EventSubmissionFactory
    {
        return EventSubmissionFactory::new();
    }
}
