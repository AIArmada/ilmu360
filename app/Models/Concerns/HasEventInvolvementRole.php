<?php

namespace App\Models\Concerns;

trait HasEventInvolvementRole
{
    public function getRoleAttribute(?string $value): ?string
    {
        return $value ?? $this->metadata['role_code'] ?? null;
    }

    public function setRoleAttribute(?string $value): void
    {
        $metadata = $this->metadata ?? [];
        $metadata['role_code'] = $value;
        $this->metadata = $metadata;
    }

    public function getOrderColumnAttribute(?int $value): ?int
    {
        return $value ?? $this->metadata['sort_order'] ?? null;
    }

    public function setOrderColumnAttribute(?int $value): void
    {
        $metadata = $this->metadata ?? [];
        $metadata['sort_order'] = $value;
        $this->metadata = $metadata;
    }

    public function getDisplayNameAttribute(): string
    {
        $speakerName = $this->speaker?->formatted_name;
        if (is_string($speakerName) && $speakerName !== '') {
            return $speakerName;
        }

        return (string) ($this->name ?? 'Key Person');
    }
}
