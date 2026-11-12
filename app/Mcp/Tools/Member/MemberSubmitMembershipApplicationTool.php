<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Member;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Enums\MemberSubjectType;
use App\Features\Membership\Api\MembershipApplications;
use App\Features\Membership\Api\SubmitMembershipApplicationInput;
use App\Models\MembershipApplication;
use App\Models\User;
use App\Platform\Media\MediaCollectionSync;
use App\Shared\Membership\MembershipSubjectType;
use App\Support\Api\Member\MemberResourceService;
use App\Support\Mcp\McpAuthenticatedUserResolver;
use App\Support\Mcp\McpFilePayloadNormalizer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[IsReadOnly(false)]
#[IsIdempotent(false)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class MemberSubmitMembershipApplicationTool extends AbstractMemberWriteTool
{
    #[\Override]
    protected string $name = 'member-submit-membership-application';

    #[\Override]
    protected string $description = 'Use this when the authenticated Ahli/member needs to submit a new membership application with justification and supporting evidence uploads.';

    public function __construct(
        private readonly MembershipApplications $membershipApplications,
        private readonly McpFilePayloadNormalizer $filePayloadNormalizer,
        private readonly MediaCollectionSync $mediaSyncService,
    ) {}

    public function handle(Request $request): ResponseFactory|Response
    {
        return $this->structuredResponse(function () use ($request): array {
            $actor = $this->authorizeMember($request);

            $validated = $this->validateArguments($request, [
                'subject_type' => ['required', 'string'],
                'subject' => ['required', 'string'],
                'justification' => ['required', 'string', 'max:2000'],
                'evidence' => ['required', 'array', 'min:1', 'max:8'],
            ]);

            $resolvedSubjectType = MemberSubjectType::fromRouteSegment((string) $validated['subject_type'])
                ?? MemberSubjectType::tryFrom((string) $validated['subject_type']);
            abort_unless($resolvedSubjectType?->isClaimable(), 400);

            $subject = $resolvedSubjectType->resolveSubject((string) $validated['subject']);

            $normalizedMediaPayload = $this->filePayloadNormalizer->normalize($validated, [
                'evidence' => $this->evidenceMediaContract(),
            ]);

            $application = null;

            try {
                $detail = $this->membershipApplications->submit(new SubmitMembershipApplicationInput(
                    subjectType: MembershipSubjectType::from($resolvedSubjectType->value),
                    subjectId: (string) $subject->getKey(),
                    accountId: (string) $actor->getKey(),
                    justification: (string) $validated['justification'],
                ));

                $application = OwnerContext::withOwner(null, fn (): MembershipApplication => MembershipApplication::query()
                    ->findOrFail($detail->application->id));

                $this->mediaSyncService->syncMultiple(
                    $application,
                    is_array($normalizedMediaPayload['payload']['evidence'] ?? null)
                        ? $normalizedMediaPayload['payload']['evidence']
                        : null,
                    'evidence',
                    replace: true,
                );

                return [
                    'data' => [
                        'application' => [
                            'id' => $detail->application->id,
                            'status' => $detail->application->status,
                        ],
                    ],
                ];
            } catch (Throwable $exception) {
                if ($application instanceof MembershipApplication) {
                    try {
                        $this->membershipApplications->discard((string) $application->getKey());
                    } catch (Throwable $cleanupException) {
                        report($cleanupException);
                    }
                }

                if (! $exception instanceof \RuntimeException) {
                    throw $exception;
                }

                throw ValidationException::withMessages([
                    'justification' => match ($exception->getMessage()) {
                        'membership_claim_already_member' => __('You are already a member of this record.'),
                        'membership_claim_duplicate_pending' => __('You already have a pending application for this record.'),
                        'membership_claim_pending_invitation' => __('You already have a pending invitation for this record. Please accept that invitation instead.'),
                        default => __('The membership application could not be submitted.'),
                    },
                ]);
            } finally {
                $this->filePayloadNormalizer->cleanup($normalizedMediaPayload['temporary_paths']);
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
            'justification' => $schema->string()->required()->max(2000),
            'evidence' => $schema->array()->required()->min(1)->max(8)
                ->items(
                    $schema->anyOf([
                        $schema->object([
                            'filename' => $schema->string()->required()->min(1),
                            'mime_type' => $schema->string()->min(1),
                            'content_base64' => $schema->string()->required()->min(1),
                            'content_url' => $schema->string()->min(1),
                        ])->withoutAdditionalProperties(),
                        $schema->object([
                            'filename' => $schema->string()->required()->min(1),
                            'mime_type' => $schema->string()->min(1),
                            'content_base64' => $schema->string()->min(1),
                            'content_url' => $schema->string()->required()->min(1),
                        ])->withoutAdditionalProperties(),
                    ])
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

    /**
     * @return array{type: string, accepted_mime_types: list<string>, max_file_size_kb: int, max_files: int}
     */
    private function evidenceMediaContract(): array
    {
        return [
            'type' => 'array<file>',
            'accepted_mime_types' => ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],
            'max_file_size_kb' => (int) ceil(((int) config('media-library.max_file_size', 10 * 1024 * 1024)) / 1024),
            'max_files' => 8,
        ];
    }

    #[\Override]
    public function shouldRegister(Request $request, MemberResourceService $resourceService): bool
    {
        unset($resourceService);

        $user = app(McpAuthenticatedUserResolver::class)->resolve($request->user());

        return $user instanceof User && $user->hasMemberMcpAccess();
    }
}
