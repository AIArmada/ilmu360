<?php

namespace App\Filament\Pages\Concerns;

use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

trait AuditsRelatedStateChanges
{
    /**
     * @var array<string, mixed>
     */
    #[Locked]
    public array $relatedAuditSnapshot = [];

    /**
     * @return array<string, mixed>
     */
    abstract protected function getRelatedAuditSnapshot(Model $record): array;

    protected function captureRelatedAuditSnapshot(Model $record): void
    {
        $this->relatedAuditSnapshot = $this->getRelatedAuditSnapshot($record);
    }

    protected function auditRelatedStateChanges(Model $record, string $event): void
    {
        if (! $record instanceof AuditableContract || ! method_exists($record, 'recordCustomAuditDifferences')) {
            return;
        }

        $record->recordCustomAuditDifferences(
            $event,
            $this->relatedAuditSnapshot,
            $this->getRelatedAuditSnapshot($record),
        );
    }
}
