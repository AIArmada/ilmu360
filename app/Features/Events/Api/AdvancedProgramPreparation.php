<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/** Advanced multi-session program validation without mutable models. */
interface AdvancedProgramPreparation
{
    /**
     * @param array<string, mixed> $form
     * @return array{
     *     timezone: string,
     *     primary_organizer_type: 'institution'|'person',
     *     primary_organizer_id: string,
     *     location_institution_id: string|null,
     *     program_starts_at: string,
     *     program_ends_at: string
     * }
     */
    public function prepare(string $accountId, array $form, ?string $lockedInstitutionId = null, ?string $lockedPersonId = null): array;
}
