<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

use Filament\Tables\Table;

/**
 * Framework-specific table composition: Events retains all Event models,
 * queries, scopes, columns and formatting; Institutions supplies scalar state.
 */
interface InstitutionEventTable
{
    /**
     * @param  array<string, string>  $statusOptions
     * @param  array<string, string>  $visibilityOptions
     */
    public function configure(
        Table $table,
        ?string $institutionId,
        ?string $selectedInstitutionId,
        bool $canUseSelectedInstitutionForScopedSubmission,
        string $sort,
        bool $hasExplicitTableSort,
        array $statusOptions,
        array $visibilityOptions,
    ): Table;
}
