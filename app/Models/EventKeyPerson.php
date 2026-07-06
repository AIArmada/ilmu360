<?php

namespace App\Models;

use AIArmada\Events\Models\EventInvolvement;
use App\Enums\EventKeyPersonRole;
use App\Models\Concerns\AuditsModelChanges;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class EventKeyPerson extends EventInvolvement implements AuditableContract
{
    use AuditsModelChanges;

    protected $fillable = [
        'id',
        'event_id', 'speaker_id',
        'role_code', 'name', 'sort_order', 'is_public', 'notes',
        'role', 'order_column',
        'status', 'visibility', 'prominence', 'is_featured', 'is_primary',
        'starts_at', 'ends_at', 'metadata',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'role_code' => EventKeyPersonRole::class,
            'is_public' => 'boolean',
            'sort_order' => 'integer',
        ]);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    public function speaker(): BelongsTo
    {
        return $this->belongsTo(Speaker::class, 'speaker_id');
    }

    public function getDisplayNameAttribute(): string
    {
        if ($this->speaker !== null) {
            return $this->speaker->formatted_name;
        }

        return (string) ($this->name ?? '');
    }

    public function getRoleAttribute(): ?string
    {
        return $this->role_code instanceof \BackedEnum ? $this->role_code->value : $this->role_code;
    }

    public function setRoleAttribute(mixed $value): void
    {
        $this->role_code = $value instanceof \BackedEnum ? $value->value : $value;
    }

    public function getOrderColumnAttribute(): ?int
    {
        return $this->sort_order;
    }

    public function setOrderColumnAttribute(?int $value): void
    {
        $this->sort_order = $value;
    }
}
