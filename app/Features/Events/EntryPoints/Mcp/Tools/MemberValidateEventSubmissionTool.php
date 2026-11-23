<?php

declare(strict_types=1);

namespace App\Features\Events\EntryPoints\Mcp\Tools;

use App\Features\Events\Actions\Submission\PrepareEventSubmissionAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Arr;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
final class MemberValidateEventSubmissionTool extends AbstractMemberEventSubmissionTool
{
    #[\Override]
    protected string $name = 'member-validate-event-submission';

    #[\Override]
    protected string $title = 'Validate Event Submission';

    #[\Override]
    protected string $description = 'Check an ordinary event draft against submission permissions, catalog, location, and timing rules. Returns field errors or the normalized draft and effective schedule for review. Supply images and any required CAPTCHA token only to member-submit-event.';

    public function __construct(private readonly PrepareEventSubmissionAction $prepare) {}

    public function handle(Request $request): ResponseFactory|Response
    {
        return $this->structuredResponse(function () use ($request): array {
            $actor = $this->authorizeMember($request);
            $state = $this->submissionArguments($request);
            $input = $this->prepare->handle($state, $actor, scopedInstitutionId: $state['scoped_institution_id'] ?? null);

            return ['data' => [
                'draft' => array_filter(
                    Arr::except($input->state, ['space_ids', 'primary_organizer_kind', 'primary_organizer_institution_id']),
                    static fn (mixed $value): bool => $value !== null,
                ),
                'starts_at' => $input->startsAt,
                'ends_at' => $input->endsAt,
                'timezone' => $input->timezone,
                'prayer_source' => $input->prayerSource,
            ]];
        });
    }

    /** @return array<string, Type> */
    #[\Override]
    public function outputSchema(JsonSchema $schema): array
    {
        return ['data' => $schema->object([
            'draft' => $schema->object()->required(),
            'starts_at' => $schema->string()->format('date-time')->required(),
            'ends_at' => $schema->string()->format('date-time')->nullable()->required(),
            'timezone' => $schema->string()->required(),
            'prayer_source' => $schema->string()->nullable()->required(),
        ])->required()];
    }
}
