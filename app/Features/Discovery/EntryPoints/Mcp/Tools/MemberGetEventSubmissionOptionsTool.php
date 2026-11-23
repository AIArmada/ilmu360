<?php

declare(strict_types=1);

namespace App\Features\Discovery\EntryPoints\Mcp\Tools;

use App\Features\Discovery\Services\CatalogDispatcher;
use App\Features\Events\Api\EventCategoryCatalog;
use App\Features\Events\Api\SubmissionTimingContract;
use App\Platform\Mcp\Tools\Member\AbstractMemberTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
final class MemberGetEventSubmissionOptionsTool extends AbstractMemberTool
{
    #[\Override]
    protected string $name = 'member-get-event-submission-options';

    #[\Override]
    protected string $title = 'Get Event Submission Options';

    #[\Override]
    protected string $description = 'Get valid country, event category, and language UUIDs for submission_country_id, event_category_ids, and languages on an event submission.';

    public function __construct(
        private readonly CatalogDispatcher $catalogs,
        private readonly EventCategoryCatalog $categories,
        private readonly SubmissionTimingContract $timing,
    ) {}

    public function handle(Request $request): ResponseFactory|Response
    {
        return $this->structuredResponse(function () use ($request): array {
            $this->authorizeMember($request);
            $this->validateArguments($request, []);

            $categories = $this->categories->options();
            $timezones = $this->timing->countryTimezoneCatalog();

            return [
                'data' => [
                    'countries' => array_map(
                        static fn (array $country): array => $country + ['timezones' => $timezones[$country['id']] ?? []],
                        $this->catalogs->countries(),
                    ),
                    'categories' => array_map(
                        static fn (string $id, string $label): array => ['id' => $id, 'label' => $label],
                        array_keys($categories),
                        array_values($categories),
                    ),
                    'languages' => $this->catalogs->languages(),
                ],
            ];
        });
    }

    #[\Override]
    public function outputSchema(JsonSchema $schema): array
    {
        $optionProperties = [
            'id' => $schema->string()->format('uuid')->required(),
            'label' => $schema->string()->required(),
        ];

        return [
            'data' => $schema->object([
                'countries' => $schema->array()->items($schema->object([
                    ...$optionProperties,
                    'iso2' => $schema->string()->required(),
                    'key' => $schema->string()->nullable(),
                    'timezones' => $schema->array()->items($schema->string())->required(),
                ]))->required(),
                'categories' => $schema->array()->items($schema->object($optionProperties))->required(),
                'languages' => $schema->array()->items($schema->object($optionProperties))->required(),
            ])->required(),
        ];
    }
}
