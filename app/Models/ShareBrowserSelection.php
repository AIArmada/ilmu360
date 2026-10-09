<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ShareBrowserSelectionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShareBrowserSelection extends Model
{
    /** @use HasFactory<ShareBrowserSelectionFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'browser_key',
        'current_attribution_id',
        'accepted_at',
    ];

    protected $hidden = [
        'browser_key',
    ];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): ShareBrowserSelectionFactory
    {
        return ShareBrowserSelectionFactory::new();
    }
}
