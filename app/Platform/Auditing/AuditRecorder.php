<?php

declare(strict_types=1);

namespace App\Platform\Auditing;

interface AuditRecorder
{
    public function record(AuditRecord $record): void;
}
