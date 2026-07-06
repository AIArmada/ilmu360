<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Member;

use AIArmada\Membership\Actions\ApplyForMembershipAction;
use App\Enums\MemberSubjectType;
use App\Models\User;
use App\Support\Api\Member\MemberResourceService;
use App\Support\Mcp\McpAuthenticatedUserResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsIdempotent(false)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class MemberSubmitMembershipClaimTool extends AbstractMemberWriteTool
{
    protected string $name = 'member-submit-membership-claim';

    protected string $description = 'Use this when the authenticated Ahli/member needs to submit a new membership claim with justification and supporting evidence uploads.';

    public function __construct(
        private ApplyForMembershipAction $applyForMembershipAction,
    ) {}

    public function handle(Request $request): ResponseFactory|Response
    {
        return $this->structuredResponse(function () use ($request): array {
            $actor = $this->authorizeMember($request);

            $validated = $this->validateArguments($request, [
                'subject_type' => ['required', 'string'],
                'subject' => ['required', 'string'],
                'justification' => ['required', 'string'],
                'evidence' => ['required', 'array'],
            ]);

            $resolvedSubjectType = MemberSubjectType::fromRouteSegment((string) $validated['subject_type'])
                ?? MemberSubjectType::tryFrom((string) $validated['subject_type']);
            abort_unless($resolvedSubjectType?->isClaimable(), 400);

            $subject = $resolvedSubjectType->resolveSubject((string) $validated['subject']);

            $application = $this->applyForMembershipAction->handle(
                $subject,
                $actor,
                (string) $validated['justification'],
            );

            return [
                'data' => [
                    'application' => [
                        'id' => $application->getKey(),
                        'status' => $application->status->value,
                    ],
                ],
            ];
        });
    }

    /**
     * @return array<string, Type>
     */
    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'subject_type' => $schema->string()->required()->enum($this->subjectTypeValues()),
            'subject' => $schema->string()->required()->min(1),
            'justification' => $schema->string()->required(),
            'evidence' => $schema->array()->required()
                ->items(
                    $schema->object([
                        'filename' => $schema->string()->required()->min(1),
                        'mime_type' => $schema->string()->min(1),
                        'content_base64' => $schema->string()->min(1),
                        'content_url' => $schema->string()->min(1),
                    ])->withoutAdditionalProperties()
                )
                ->description('Array of MCP file descriptors. Each item must include filename plus either content_base64 or content_url. Multipart/form-data is not supported for MCP tools.'),
        ];
    }

    /**
     * @return list<string>
     */
    private function subjectTypeValues(): array
    {
        return array_values(array_unique(array_merge(
            array_map(static fn (MemberSubjectType $type): string => $type->value, MemberSubjectType::claimableCases()),
            MemberSubjectType::claimableRouteSegments(),
        )));
    }

    #[\Override]
    public function shouldRegister(Request $request, MemberResourceService $resourceService): bool
    {
        unset($resourceService);

        $user = app(McpAuthenticatedUserResolver::class)->resolve($request->user());

        return $user instanceof User && $user->hasMemberMcpAccess();
    }
}
