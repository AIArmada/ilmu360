<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

class EventKeyPersonPivot extends Pivot
{
    use HasUuids;

    protected $table = 'event_key_people';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'event_id',
        'speaker_id',
        'role',
        'name',
        'order_column',
        'is_public',
        'notes',
    ];
}
