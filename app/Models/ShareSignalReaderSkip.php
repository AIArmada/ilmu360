<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Durable per-event poison memory for one named Signals reader.
 *
 * The cursor high-water alone cannot remember which behind-cursor
 * events were already counted as poison: without this table a
 * backlog sweep for late arrivals would reselect every skipped
 * poison event forever. Each row marks one signal event the reader
 * has already counted as failed; backlog selection excludes these,
 * while forward selection still advances the high-water past them.
 * Operator replay via resetCursor() clears these rows so retained
 * poison is retried once against recovered sources.
 */
class ShareSignalReaderSkip extends Model
{
    use HasUuids;

    protected $fillable = [
        'reader_name',
        'signal_event_id',
    ];
}
