<?php

namespace App\Models;

use App\Models\Concerns\AuditsModelChanges;
use Database\Factories\MediaLinkFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class MediaLink extends Model implements AuditableContract
{
    /** @use HasFactory<MediaLinkFactory> */
    use AuditsModelChanges, HasFactory, HasUuids;

    protected $table = 'media_links';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'mediable_type',
        'mediable_id',
        'type',
        'provider',
        'url',
        'is_primary',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function mediable(): MorphTo
    {
        return $this->morphTo();
    }
}
