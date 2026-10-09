<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Durable cursor for one named Signals reader.
 *
 * The reader advances (last_occurred_at, last_signal_event_id) in the
 * SAME transaction as its final effect, so a crash resumes exactly
 * after the last completed event — never re-applied (effects are
 * idempotent regardless), never skipped. Cursor rows are never
 * deleted; an operator rewinds by resetting the position columns.
 */
class ShareSignalReaderCursor extends Model
{
    use HasUuids;

    protected $fillable = [
        'reader_name',
        'last_occurred_at',
        'last_signal_event_id',
        'processed_total',
    ];

    protected function casts(): array
    {
        return [
            'last_occurred_at' => 'immutable_datetime',
            'processed_total' => 'integer',
        ];
    }
}
