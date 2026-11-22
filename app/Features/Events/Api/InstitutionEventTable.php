<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

use Closure;
use Filament\Tables\Table;

/**
 * Framework-specific table composition: Events retains all Event models,
 * queries, scopes, columns and formatting; Institutions supplies scalar state.
 */
interface InstitutionEventTable
{
    /**
     * @param  Closure(): array{sort: string, hasExplicitTableSort: bool}  $sortState
     * @param  array<string, string>  $statusOptions
     * @param  array<string, string>  $visibilityOptions
     */
    public function configure(
        Table $table,
        ?string $institutionId,
        ?string $selectedInstitutionId,
        bool $canUseSelectedInstitutionForScopedSubmission,
        Closure $sortState,
        array $statusOptions,
        array $visibilityOptions,
    ): Table;
}
