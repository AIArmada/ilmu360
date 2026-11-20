<?php

namespace App\Features\Events\EntryPoints\Http;

use AIArmada\Organizations\Models\Organization;
use App\Features\Events\Api\AdvancedEventCreator;
use App\Features\Events\Api\AdvancedProgramPreparation;
use App\Features\Events\Api\Enums\EventFormat;
use App\Features\Events\Api\Enums\EventVisibility;
use App\Features\Events\Api\Orm\EventCategoryCatalog;
use App\Features\Events\Api\RegistrationScope;
use App\Features\Events\Models\Event;
use App\Features\Institutions\Api\Orm\InstitutionLookup;
use App\Features\Organizations\Api\OrganizationAccess;
use App\Features\Organizations\Api\OrganizationSummary;
use App\Features\Persons\Api\Orm\PersonLookup;
use App\Platform\Http\FrontendController;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[Group(
    'Advanced Event',
    'Authenticated event, occurrence, and session creation flow. '
    .'Create an event container with its first occurrence, then add sessions beneath that occurrence.',
    weight: 31,
)]
class AdvancedEventController extends FrontendController
{
    #[Endpoint(
        title: 'Create an advanced event',
        description: 'Creates an authenticated event with its first occurrence and returns the session-submission endpoint. '
            .'Fetch `GET /forms/advanced-events` first to discover the exact required fields and option catalogs.',
    )]
    public function store(
        Request $request,
        AdvancedProgramPreparation $advancedProgramPreparation,
        AdvancedEventCreator $advancedEventCreator,
        OrganizationAccess $organizationAccess,
    ): JsonResponse {
        $user = $this->requireUser($request);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'timezone' => ['required', 'timezone'],
            'program_starts_at' => ['required', 'date'],
            'program_ends_at' => ['required', 'date'],
            'primary_organizer_id' => ['required', 'uuid'],
            'organization_id' => ['nullable', 'uuid'],
            'location_institution_id' => ['nullable', 'uuid'],
            'default_event_category_ids' => ['required', 'array', 'min:1'],
            'default_event_category_ids.*' => ['uuid', Rule::in(app(EventCategoryCatalog::class)->validTermIds((array) $request->input('default_event_category_ids', [])))],
            'default_event_format' => ['required', Rule::in(array_column(EventFormat::cases(), 'value'))],
            'visibility' => ['required', Rule::in(array_column(EventVisibility::cases(), 'value'))],
            'registration_required' => ['required', 'boolean'],
            'registration_mode' => ['required', Rule::in(array_column(RegistrationScope::cases(), 'value'))],
        ]);

        $preparedSubmission = $advancedProgramPreparation->prepare((string) $user->getKey(), $validated);
        match ($preparedSubmission['primary_organizer_type']) {
            'institution' => app(InstitutionLookup::class)->assertExists($preparedSubmission['primary_organizer_id']),
            'person' => app(PersonLookup::class)->assertExists($preparedSubmission['primary_organizer_id']),
        };
        $organization = null;

        if (filled($validated['organization_id'] ?? null)) {
            $organizationSummary = $organizationAccess->findForAccount(
                (string) $user->getKey(),
                (string) $validated['organization_id'],
            );

            abort_if(! $organizationSummary instanceof OrganizationSummary, 404);

            $organization = Organization::query()->findOrFail($organizationSummary->id);
        }

        $eventId = $advancedEventCreator->create(
            actorId: (string) $user->getKey(),
            form: $validated,
            startsAt: $preparedSubmission['program_starts_at'],
            endsAt: $preparedSubmission['program_ends_at'],
            timezone: $preparedSubmission['timezone'],
            primaryOrganizerType: $preparedSubmission['primary_organizer_type'],
            primaryOrganizerId: $preparedSubmission['primary_organizer_id'],
            locationInstitutionId: $preparedSubmission['location_institution_id'],
            organizationId: $organization !== null ? (string) $organization->getKey() : null,
        );
        $event = Event::query()->withoutOwnerScope()->findOrFail($eventId);

        return response()->json([
            'data' => [
                'event' => [
                    'id' => $event->getKey(),
                    'slug' => $event->slug,
                    'title' => $event->title,
                    'status' => (string) $event->status,
                ],
                'next_submit_event_endpoint' => route('api.client.forms.submit-event', ['event_id' => $event->getKey()]),
            ],
            'meta' => [
                'request_id' => $this->requestId($request),
            ],
        ], 201);
    }
}
