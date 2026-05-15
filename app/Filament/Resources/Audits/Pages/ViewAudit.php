<?php

declare(strict_types=1);

namespace App\Filament\Resources\Audits\Pages;

use App\Filament\Resources\Audits\AuditResource;
use Filament\Resources\Pages\ViewRecord;

class ViewAudit extends ViewRecord
{
    protected static string $resource = AuditResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [];
    }
}
