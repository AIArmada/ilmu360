<?php

declare(strict_types=1);

namespace App\Features\Events\Api\Orm;

use App\Features\Events\Models\Event;
use Illuminate\Database\Eloquent\Model;

/**
 * Contribution form-state normalisation boundary for event subjects.
 *
 * The object parameters are the hydrated event or subject model; the owner
 * implementation narrows them to its own model types.
 *
 * @internal Explicit ORM integration port for framework callbacks, hydrated page state and builder composition.
 *
 * Consumers: Providers/AppServiceProvider.php, Events/Services, Contributions/EntryPoints
 */
interface EventContributionStateMapper
{
    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function toHelperState(array $state): array;

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function toPersistenceState(array $state, ?Event $event = null): array;

    /**
     * @return array<string, mixed>
     */
    public function provenanceOriginals(Model $entity): array;

    /**
     * @param  array<string, mixed>  $formState
     */
    public function effectiveOptionsCountry(array $formState, ?Event $event = null): string;
}
