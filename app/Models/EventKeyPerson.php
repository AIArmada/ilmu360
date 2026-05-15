<?php

namespace App\Models;

use App\Enums\EventKeyPersonRole;
use App\Models\Concerns\AuditsModelChanges;
use Database\Factories\EventKeyPersonFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class EventKeyPerson extends Model implements AuditableContract
{
    /** @use HasFactory<EventKeyPersonFactory> */
    use AuditsModelChanges, HasFactory, HasUuids;

    protected $table = 'event_key_people';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'speaker_id',
        'role',
        'name',
        'order_column',
        'is_public',
        'notes',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'role' => EventKeyPersonRole::class,
            'order_column' => 'integer',
            'is_public' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return BelongsTo<Speaker, $this>
     */
    public function speaker(): BelongsTo
    {
        return $this->belongsTo(Speaker::class);
    }

    public function getDisplayNameAttribute(): string
    {
        if ($this->speaker !== null) {
            return $this->speaker->formatted_name;
        }

        return (string) ($this->name ?? '');
    }
}
