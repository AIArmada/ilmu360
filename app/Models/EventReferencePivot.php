<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\MorphPivot;

/**
 * @property string $referenceable_type
 * @property string $reference_type
 * @property string $visibility
 * @property int $sort_order
 */
class EventReferencePivot extends MorphPivot
{
    use HasUuids;

    #[\Override]
    protected $table = 'event_references';

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
        'referenceable_type',
        'referenceable_id',
        'reference_type',
        'title',
        'visibility',
        'sort_order',
        'notes',
        'metadata',
    ];

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (self $pivot): void {
            $pivot->referenceable_type ??= 'reference';
            $pivot->reference_type ??= 'book';
            $pivot->visibility ??= 'public';
            $pivot->sort_order ??= 0;
        });
    }
}
