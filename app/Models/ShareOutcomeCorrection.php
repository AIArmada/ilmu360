<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ShareOutcomeCorrectionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShareOutcomeCorrection extends Model
{
    /** @use HasFactory<ShareOutcomeCorrectionFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'conversion_id',
        'business_key',
        'reason',
        'actor_type',
        'actor_id',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): ShareOutcomeCorrectionFactory
    {
        return ShareOutcomeCorrectionFactory::new();
    }
}
