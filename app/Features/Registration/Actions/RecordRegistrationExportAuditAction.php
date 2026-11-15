<?php

declare(strict_types=1);

namespace App\Features\Registration\Actions;

use App\Platform\Auditing\AuditRecord;
use App\Platform\Auditing\AuditRecorder;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Writes the export audit with the already-authenticated actor and morph aliases.
 */
final readonly class RecordRegistrationExportAuditAction
{
    use AsAction;

    public function __construct(private AuditRecorder $auditRecorder) {}

    public function handle(
        string $eventClass,
        string $eventId,
        string $actorType,
        string $actorId,
        int $count,
        string $url,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        $this->auditRecorder->record(new AuditRecord(
            event: 'export_registrations',
            actorType: $actorType,
            actorId: $actorId,
            subjectType: $eventClass,
            subjectId: $eventId,
            oldValues: [],
            newValues: [
                'count' => $count,
                'exported_at' => now()->toIso8601String(),
            ],
            url: $url,
            ipAddress: $ipAddress,
            userAgent: $userAgent,
        ));
    }
}
