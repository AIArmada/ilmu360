<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EventTermPolicy extends Model
{
    use HasUuids;

    protected $fillable = [
        'event_term_id',
        'policy_code',
        'is_enabled',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }
}
