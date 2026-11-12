<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

class EventKeyPersonPivot extends Pivot
{
    use HasUuids;

    #[\Override]
    protected $table = 'event_involvements';

    #[\Override]
    public $incrementing = false;

    #[\Override]
    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    #[\Override]
    protected $fillable = [
        'id',
        'event_id',
        'role_code',
        'status',
        'visibility',
        'sort_order',
        'notes',
    ];
}
