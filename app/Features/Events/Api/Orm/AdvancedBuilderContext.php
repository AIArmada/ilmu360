<?php

declare(strict_types=1);

namespace App\Features\Events\Api\Orm;

/** Default form projection for an account's accessible event organizers.
 *
 * @internal Explicit ORM integration port for framework callbacks, hydrated page state and builder composition.
 *
 * Consumers: Platform/Api, Events/FeatureServiceProvider.php, Events/Actions, Events/Api, Events/EntryPoints
 */
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
