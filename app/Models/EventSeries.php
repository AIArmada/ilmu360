<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property string|null $event_id
 * @property string|null $seriesable_type
 * @property string|null $seriesable_id
 */
class EventSeries extends Pivot
{
    use HasUuids;

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (self $pivot): void {
            if (($pivot->seriesable_type === null || $pivot->seriesable_type === '') && filled($pivot->event_id)) {
                $pivot->seriesable_type = Event::class;
            }

            if (($pivot->seriesable_id === null || $pivot->seriesable_id === '') && $pivot->seriesable_type === Event::class && filled($pivot->event_id)) {
                $pivot->seriesable_id = $pivot->event_id;
            }
        });
    }

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'event_series_items';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'event_series_id',
        'seriesable_type',
        'seriesable_id',
        'event_id',
        'event_occurrence_id',
        'event_session_id',
        'title_override',
        'starts_at',
        'order_column',
        'sort_order',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'sort_order' => 'integer',
            'metadata' => 'array',
        ];
    }

    #[\Override]
    public function setAttribute($key, $value): mixed
    {
        if ($key === 'order_column') {
            $key = 'sort_order';
        }

        return parent::setAttribute($key, $value);
    }

    #[\Override]
    public function getAttribute($key): mixed
    {
        if ($key === 'order_column') {
            $key = 'sort_order';
        }

        return parent::getAttribute($key);
    }
}
