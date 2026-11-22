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
     * @param  Closure(): array{institutionId: ?string, selectedInstitutionId: ?string, canUseSelectedInstitutionForScopedSubmission: bool}  $scopeState
     * @param  Closure(): array{sort: string, hasExplicitTableSort: bool}  $sortState
     * @param  array<string, string>  $statusOptions
     * @param  array<string, string>  $visibilityOptions
     */
    public function configure(
        Table $table,
        Closure $scopeState,
        Closure $sortState,
        array $statusOptions,
        array $visibilityOptions,
    ): Table;
}
