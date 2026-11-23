<?php

declare(strict_types=1);

namespace App\Features\Prayer\EntryPoints\Mcp\Tools;

use App\Features\Prayer\Actions\BuildPrayerPreviewAction;
use App\Features\Prayer\Support\PrayerPreviewRules;
use App\Platform\Mcp\Tools\Member\AbstractMemberTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class MemberPreviewEventPrayerTimeTool extends AbstractMemberTool
{
    #[\Override]
    protected string $name = 'member-preview-event-prayer-time';

    #[\Override]
    protected string $title = 'Preview Event Prayer Times';

    #[\Override]
    protected string $description = 'Preview local start times for prayer-relative event labels on a date and at a venue location. Returns clocks keyed by submission prayer_time values, plus timing source and freshness. Provider estimates may fall back when precise timing is unavailable.';

    public function __construct(private readonly BuildPrayerPreviewAction $preview) {}

    public function handle(Request $request): ResponseFactory|Response
    {
        return $this->structuredResponse(function () use ($request): array {
            $this->authorizeMember($request);
            $rules = PrayerPreviewRules::inputs();
            $this->rejectUnexpectedArguments($request, array_keys($rules));
            $validated = Validator::make($request->all(), $rules)
                ->after(PrayerPreviewRules::validateCalendarDate(...))
                ->validate();

            $district = trim((string) ($validated['district'] ?? ''));

            try {
                return [
                    'data' => $this->preview->handle(
                        country: (string) ($validated['country'] ?? 'MY'),
                        date: (string) $validated['date'],
                        timezone: isset($validated['timezone']) ? (string) $validated['timezone'] : null,
                        zone: isset($validated['zone']) ? (string) $validated['zone'] : null,
                        latitude: isset($validated['lat']) ? (float) $validated['lat'] : null,
                        longitude: isset($validated['lng']) ? (float) $validated['lng'] : null,
                        stateCode: isset($validated['state']) ? (string) $validated['state'] : null,
                        districtCandidates: $district === '' ? [] : [$district],
                    ),
                ];
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(['zone' => ['Unknown prayer zone code.']]);
            }
        });
    }

    /** @return array<string, Type> */
    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'date' => $schema->string()->format('date')->required()->description('Event-local date in YYYY-MM-DD format.'),
            'country' => $schema->string()->min(2)->max(2)->default('MY')->description('Venue country ISO 3166-1 alpha-2 code.'),
            'timezone' => $schema->string()->description('IANA timezone for the venue. Defaults to the country prayer provider timezone.'),
            'zone' => $schema->string()->max(10)->description('Explicit prayer-zone code when known.'),
            'district' => $schema->string()->max(100)->description('Venue district or area name for prayer-zone matching.'),
            'lat' => $schema->number()->min(-90)->max(90)->description('Venue latitude. Supply it with longitude.'),
            'lng' => $schema->number()->min(-180)->max(180)->description('Venue longitude. Supply it with latitude.'),
            'state' => $schema->string()->max(10)->description('Venue state code for prayer-zone matching.'),
        ];
    }

    /** @return array<string, Type> */
    #[\Override]
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'data' => $schema->object([
                'date' => $schema->string()->format('date')->required(),
                'country' => $schema->string()->required(),
                'timezone' => $schema->string()->required(),
                'zone' => $schema->string()->nullable()->required(),
                'location_source' => $schema->string()->nullable()->required(),
                'source' => $schema->string()->required(),
                'exact' => $schema->boolean()->required(),
                'stale' => $schema->boolean()->required(),
                'fetched_at' => $schema->string()->nullable()->required(),
                'starts' => $schema->object()->required()->description('Local HH:mm start clocks or null, keyed by prayer_time values.'),
            ])->required(),
        ];
    }
}
