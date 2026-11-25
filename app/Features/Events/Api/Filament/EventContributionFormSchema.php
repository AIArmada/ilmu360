<?php

declare(strict_types=1);

namespace App\Features\Events\Api\Filament;

use Closure;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Component;

/**
 * Canonical public event form field definition shared by the submit-event
 * wizard and the contribution update tabs.
 *
 * Entrypoints compose these components and remain responsible for their own
 * shell (wizard steps, tabs, review step, submitter contact, CAPTCHA) and for
 * the differences exposed through EventContributionFormContext. They must not
 * redefine the shared field set, order, copy, options or requirements.
 *
 * Consumers: Events/EntryPoints/Livewire/Pages/SubmitEvent,
 * Contributions/EntryPoints/Livewire/Pages/SuggestUpdate
 */
interface EventContributionFormSchema
{
    /**
     * Topic components in canonical order.
     *
     * @return array<int, Component>
     */
    public function topicComponents(EventContributionFormContext $context): array;

    /**
     * Schedule components in canonical order.
     *
     * @return array<int, Component>
     */
    public function scheduleComponents(EventContributionFormContext $context, ?Component $occurrenceSelector = null): array;

    /**
     * Attendance components in canonical order.
     *
     * @return array<int, Component>
     */
    public function attendanceComponents(EventContributionFormContext $context): array;

    /**
     * Submission country components; create-only, empty for update.
     *
     * @return array<int, Component>
     */
    public function countryComponents(EventContributionFormContext $context): array;

    /**
     * Format, links and organizer components in canonical order.
     *
     * @param  (Closure(Select): Select)|null  $configureInstitutionSelect
     * @return array<int, Component>
     */
    public function organizerComponents(EventContributionFormContext $context, ?Closure $configureInstitutionSelect = null): array;

    /**
     * Location components in canonical order.
     *
     * @param  (Closure(Select): Select)|null  $configureInstitutionSelect
     * @return array<int, Component>
     */
    public function locationComponents(EventContributionFormContext $context, ?Closure $configureInstitutionSelect = null): array;

    /**
     * Speaker and role components in canonical order.
     *
     * @return array<int, Component>
     */
    public function speakerComponents(EventContributionFormContext $context): array;
}
