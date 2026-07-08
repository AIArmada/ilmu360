<?php

namespace App\Models;

use AIArmada\Events\Models\EventInvolvement;
use AIArmada\Events\Models\EventRole;
use App\Enums\EventKeyPersonRole;
use App\Models\Concerns\AuditsModelChanges;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class EventKeyPerson extends EventInvolvement implements AuditableContract
{
    use AuditsModelChanges;

    protected $fillable = [
        'id',
        'event_id',
        'involveable_type', 'involveable_id',
        'role_code', 'sort_order', 'notes',
        'role', 'order_column',
        'status', 'visibility', 'prominence', 'is_featured', 'is_primary',
        'starts_at', 'ends_at', 'metadata',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'role_code' => EventKeyPersonRole::class,
            'sort_order' => 'integer',
        ]);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    public function speaker(): BelongsTo
    {
        return $this->belongsTo(Speaker::class, 'involveable_id');
    }

    public function getDisplayNameAttribute(): string
    {
        if ($this->speaker !== null) {
            return $this->speaker->formatted_name;
        }

        return $this->role_code instanceof \BackedEnum ? $this->role_code->value : (string) $this->role_code;
    }

    public function getRoleAttribute(): ?string
    {
        return $this->role?->code ?? ($this->role_code instanceof \BackedEnum ? $this->role_code->value : $this->role_code);
    }

    public function setRoleAttribute(mixed $value): void
    {
        $code = $value instanceof \BackedEnum ? $value->value : $value;
        $eventRole = EventRole::query()->where('code', $code)->first();

        if ($eventRole instanceof EventRole) {
            $this->event_role_id = $eventRole->id;
        }

        $this->role_code = $code;
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
