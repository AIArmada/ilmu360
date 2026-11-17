<?php

declare(strict_types=1);

namespace App\Features\Events\Api;

/** Default form projection for an account's accessible event organizers. */
interface AdvancedBuilderContext
{
    /**
     * @return array{
     *     institution_options: array<string, string>,
     *     person_options: array<string, string>,
     *     default_form: array<string, mixed>
     * }
     */
    public function forAccount(string $accountId, ?string $requestedInstitutionId = null, ?string $requestedPersonId = null): array;
}
