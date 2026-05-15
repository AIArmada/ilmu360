<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Member;

use App\Enums\MemberSubjectType;
use App\Models\User;
use App\Support\Api\Member\MemberMembershipClaimWorkflowService;
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
        private readonly MemberMembershipClaimWorkflowService $workflowService,
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

            /** @var array<string, mixed> $payload */
            $payload = [
                'justification' => $validated['justification'],
                'evidence' => $validated['evidence'],
            ];

            $this->ensureDestructiveMediaClearFlagsAreUnsupported($payload);
            $normalizedMediaPayload = $this->normalizeMcpMediaPayload($payload, $this->membershipClaimSchemaResponse());

            try {
                return $this->workflowService->submit(
                    subjectType: (string) $validated['subject_type'],
                    subject: (string) $validated['subject'],
                    payload: $normalizedMediaPayload['payload'],
                    actor: $actor,
                );
            } finally {
                $this->cleanupMcpMediaPayload($normalizedMediaPayload);
            }
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
     * @return array<string, mixed>
     */
    private function membershipClaimSchemaResponse(): array
    {
        return [
            'data' => [
                'schema' => [
                    'fields' => [
                        [
                            'name' => 'evidence',
                            'type' => 'array<file>',
                            'required_fields' => ['filename', 'content_base64_or_content_url'],
                            'accepted_mime_types' => ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],
                            'max_file_size_kb' => $this->maxUploadSizeKb(),
                            'max_files' => 8,
                        ],
                    ],
                ],
            ],
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

    private function maxUploadSizeKb(): int
    {
        return (int) ceil(((int) config('media-library.max_file_size', 10 * 1024 * 1024)) / 1024);
    }
}
