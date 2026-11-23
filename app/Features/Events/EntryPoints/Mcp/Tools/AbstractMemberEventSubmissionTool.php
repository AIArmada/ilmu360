<?php

declare(strict_types=1);

namespace App\Features\Events\EntryPoints\Mcp\Tools;

use App\Features\Events\Api\Enums\EventAgeGroup;
use App\Features\Events\Api\Enums\EventFormat;
use App\Features\Events\Api\Enums\EventGenderRestriction;
use App\Features\Events\Api\Enums\EventVisibility;
use App\Features\Prayer\Api\EventPrayerTime;
use App\Platform\Mcp\Tools\Member\AbstractMemberTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;

abstract class AbstractMemberEventSubmissionTool extends AbstractMemberTool
{
    /** @return array<string, Type> */
    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        $uuid = $schema->string()->format('uuid');

        return [
            'title' => $schema->string()->max(255)->required(),
            'description' => $schema->string()->description('Event description in plain text.'),
            'event_category_ids' => $schema->array()->items(clone $uuid)->min(1)->required()
                ->description('Category IDs from member-get-event-submission-options.'),
            'languages' => $schema->array()->items(clone $uuid)->min(1)->required()
                ->description('Language IDs from member-get-event-submission-options.'),
            'submission_country_id' => (clone $uuid)->required()
                ->description('Country ID from member-get-event-submission-options.'),
            'submission_timezone' => $schema->string()->max(64)
                ->description('IANA timezone. Required when the selected country has multiple timezones.'),
            'event_date' => $schema->string()->format('date')->required()->description('Date in the event’s local timezone.'),
            'prayer_time' => $schema->string()->enum(array_column(EventPrayerTime::cases(), 'value'))->required()
                ->description('Timing slot. For a custom clock, also supply custom_time.'),
            'custom_time' => $schema->string()->description('Local start clock as HH:mm when using custom timing.'),
            'end_time' => $schema->string()->description('Optional local end clock as HH:mm.'),
            'primary_organizer_id' => (clone $uuid)->required()
                ->description('Institution or person UUID returned by an event submission selector.'),
            'scoped_institution_id' => (clone $uuid)
                ->description('Supply only to submit on behalf of an institution you manage.'),
            'persons' => $schema->array()->items(clone $uuid)
                ->description('Speaker IDs from member-find-event-speakers.'),
            'event_format' => $schema->string()->enum(array_column(EventFormat::cases(), 'value'))->default(EventFormat::Physical->value),
            'visibility' => $schema->string()->enum(array_column(EventVisibility::cases(), 'value'))->default(EventVisibility::Public->value),
            'gender' => $schema->string()->enum(array_column(EventGenderRestriction::cases(), 'value'))->default(EventGenderRestriction::All->value),
            'age_group' => $schema->array()->items($schema->string()->enum(array_column(EventAgeGroup::cases(), 'value')))->min(1),
            'children_allowed' => $schema->boolean(),
            'is_muslim_only' => $schema->boolean(),
            'event_url' => $schema->string()->format('uri')->max(255),
            'live_url' => $schema->string()->format('uri')->max(255),
            'location_same_as_institution' => $schema->boolean()->default(true)
                ->description('Set false if the event takes place away from the organizing institution.'),
            'location_type' => $schema->string()->enum(['institution', 'venue']),
            'location_institution_id' => clone $uuid,
            'location_venue_id' => clone $uuid,
            'space_id' => clone $uuid,
            'notes' => $schema->string()->max(1000)->description('Private note for the submission reviewer.'),
        ];
    }

    /**
     * @param  array<string, list<string>>  $additionalRules
     * @return array<string, mixed>
     */
    protected function submissionArguments(Request $request, array $additionalRules = []): array
    {
        return $this->validateArguments($request, [
            'title' => ['required', 'string', 'max:255'],
            'event_category_ids' => ['required', 'array', 'min:1'],
            'event_category_ids.*' => ['required', 'uuid'],
            'submission_timezone' => ['sometimes', 'string', 'max:64'],
            'persons' => ['sometimes', 'array'],
            'persons.*' => ['required', 'uuid'],
            'event_format' => ['sometimes', 'string'],
            'visibility' => ['sometimes', 'string'],
            'gender' => ['sometimes', 'string'],
            'age_group' => ['sometimes', 'array'],
            'age_group.*' => ['required', 'string'],
            'children_allowed' => ['sometimes', 'boolean'],
            'is_muslim_only' => ['sometimes', 'boolean'],
            'event_url' => ['sometimes', 'url:http,https', 'max:255'],
            'live_url' => ['sometimes', 'url:http,https', 'max:255'],
            'location_same_as_institution' => ['sometimes', 'boolean'],
            'location_type' => ['sometimes', 'string'],
            'location_institution_id' => ['sometimes', 'uuid'],
            'location_venue_id' => ['sometimes', 'uuid'],
            'space_id' => ['sometimes', 'uuid'],
            'notes' => ['sometimes', 'string', 'max:1000'],
            'description' => ['sometimes', 'string'],
            'event_date' => ['required', 'date_format:Y-m-d'],
            'prayer_time' => ['required', 'string'],
            'custom_time' => ['sometimes', 'date_format:H:i'],
            'end_time' => ['sometimes', 'date_format:H:i'],
            'submission_country_id' => ['required', 'uuid'],
            'scoped_institution_id' => ['sometimes', 'uuid'],
            'primary_organizer_id' => ['required', 'uuid'],
            'languages' => ['required', 'array', 'min:1'],
            'languages.*' => ['required', 'uuid'],
            ...$additionalRules,
        ]);
    }
}
