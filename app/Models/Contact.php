<?php

namespace App\Models;

use App\Enums\ContactCategory;
use App\Enums\ContactType;
use App\Models\Concerns\AuditsModelChanges;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class Contact extends Model implements AuditableContract
{
    use AuditsModelChanges, HasUuids;

    protected $fillable = [
        'contactable_type',
        'contactable_id',
        'type',
        'category',
        'value',
        'order_column',
        'is_public',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'category' => ContactCategory::class,
            'type' => ContactType::class,
            'order_column' => 'integer',
            'is_public' => 'boolean',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function contactable(): MorphTo
    {
        return $this->morphTo();
    }
}
