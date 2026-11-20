<?php

declare(strict_types=1);

namespace App\Features\Events\Api\Orm;

/**
 * Advanced program builder's owner-qualified organizer choices.
 * No model or membership-package collection escapes the Events boundary.
 *
 * @internal Explicit ORM integration port for framework callbacks, hydrated page state and builder composition.
 *
 * Consumers: Events/FeatureServiceProvider.php, Events/Actions
 */
interface AdvancedBuilderMembershipOptions
{
    /**
     * @return array{
     *     institution_options: array<string, string>,
     *     person_options: array<string, string>
     * }
     */
    public function forAccount(string $accountId): array;
}
