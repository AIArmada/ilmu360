<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

class EventKeyPersonPivot extends Pivot
{
    use HasUuids;

    protected $table = 'event_involvements';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'event_id',
        'role_code',
        'sort_order',
        'notes',
    ];
}
