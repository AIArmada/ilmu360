<?php

declare(strict_types=1);

namespace App\Platform\Auditing;

use App\Platform\Auditing\Models\Audit;
use Illuminate\Support\Str;

final readonly class EloquentAuditRecorder implements AuditRecorder
{
    public function record(AuditRecord $record): void
    {
        Audit::create([
            'id' => (string) Str::uuid(),
            'user_type' => $record->actorType,
            'user_id' => $record->actorId,
            'event' => $record->event,
            'auditable_type' => $record->subjectType,
            'auditable_id' => $record->subjectId,
            'old_values' => $record->oldValues,
            'new_values' => $record->newValues,
            'url' => $record->url,
            'ip_address' => $record->ipAddress,
            'user_agent' => $record->userAgent,
        ]);
    }
}
