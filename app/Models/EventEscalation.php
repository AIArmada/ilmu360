<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EventEscalationType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EventEscalation extends Model
{
    use HasUuids;

    protected $fillable = [
        'event_id',
        'type',
        'decision_key',
        'reason',
        'dispatched_at',
        'resolved_at',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'type' => EventEscalationType::class,
            'dispatched_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
