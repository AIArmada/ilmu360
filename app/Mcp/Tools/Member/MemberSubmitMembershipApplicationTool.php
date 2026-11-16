<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Member;

use App\Features\Membership\Api\MembershipApplications;
use App\Features\Membership\Api\MembershipApplicationSubjectType;
use App\Features\Membership\Api\SubmitMembershipApplicationInput;
use App\Mcp\Tools\Concerns\DefinesMcpFileDescriptorSchema;
use App\Models\User;
use App\Platform\Media\StagedUploadFactory;
use App\Shared\Membership\MembershipSubjectCatalog;
use App\Support\Api\Member\MemberResourceService;
use App\Support\Mcp\McpAuthenticatedUserResolver;
use App\Support\Mcp\McpFilePayloadNormalizer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\UploadedFile;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use RuntimeException;

#[IsReadOnly(false)]
#[IsIdempotent(false)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class MemberSubmitMembershipApplicationTool extends AbstractMemberWriteTool
{
    use DefinesMcpFileDescriptorSchema;

    #[\Override]
    protected string $name = 'member-submit-membership-application';

    #[\Override]
    protected string $description = 'Use this when the authenticated Ahli/member needs to submit a new membership application with justification and supporting evidence uploads.';

    public function __construct(
        private readonly MembershipApplications $membershipApplications,
        private readonly McpFilePayloadNormalizer $filePayloadNormalizer,
        private readonly MembershipSubjectCatalog $subjectCatalog,
        private readonly StagedUploadFactory $stagedUploadFactory,
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

            $resolvedSubjectType = MembershipApplicationSubjectType::tryFrom((string) $validated['subject_type']);
            abort_unless($resolvedSubjectType instanceof MembershipApplicationSubjectType, 400);

            $subjectId = $this->subjectCatalog->resolveIdentifier($resolvedSubjectType->membershipSubjectType(), (string) $validated['subject']);
            abort_unless(is_string($subjectId), 404);

            $normalizedMediaPayload = $this->filePayloadNormalizer->normalize($validated, [
                'evidence' => $this->evidenceMediaContract(),
            ]);

            try {
                $normalizedEvidence = $normalizedMediaPayload['payload']['evidence'] ?? [];

                $detail = $this->membershipApplications->submit(new SubmitMembershipApplicationInput(
                    subjectType: $resolvedSubjectType->membershipSubjectType(),
                    subjectId: $subjectId,
                    accountId: (string) $actor->getKey(),
                    justification: (string) $validated['justification'],
                    evidenceFiles: $this->stageEvidenceFiles($normalizedEvidence),
                ));

                return [
                    'data' => [
                        'application' => [
                            'id' => $detail->application->id,
                            'status' => $detail->application->status,
                        ],
                    ],
                ];
            } catch (RuntimeException $exception) {
                throw ValidationException::withMessages([
                    'justification' => match ($exception->getMessage()) {
                        'membership_application_already_member' => __('You are already a member of this record.'),
                        'membership_application_duplicate_pending' => __('You already have a pending application for this record.'),
                        'membership_application_pending_invitation' => __('You already have a pending invitation for this record. Please accept that invitation instead.'),
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
                ->items($this->fileDescriptorSchema($schema))
                ->description('Array of MCP file descriptors. Each item must include filename and exactly one of content_base64, content_url, or download_url. Multipart/form-data is not supported for MCP tools.'),
        ];
    }

    /**
     * @return list<string>
     */
    private function subjectTypeValues(): array
    {
        return array_map(
            static fn (MembershipApplicationSubjectType $type): string => $type->value,
            MembershipApplicationSubjectType::cases(),
        );
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

    /**
     * @return list<\App\Shared\Media\StagedUpload>
     */
    private function stageEvidenceFiles(mixed $files): array
    {
        if (! is_array($files) || ! array_is_list($files)) {
            throw ValidationException::withMessages([
                'evidence' => ['The evidence files could not be normalized.'],
            ]);
        }

        $uploads = [];

        foreach ($files as $index => $file) {
            if (! $file instanceof UploadedFile) {
                throw ValidationException::withMessages([
                    "evidence.{$index}" => ['The evidence file could not be normalized.'],
                ]);
            }

            try {
                $uploads[] = $this->stagedUploadFactory->fromUploadedFile($file);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages([
                    "evidence.{$index}" => ['The evidence file is no longer available.'],
                ]);
            }
        }

        return $uploads;
    }

    #[\Override]
    public function shouldRegister(Request $request, MemberResourceService $resourceService): bool
    {
        unset($resourceService);

        $user = app(McpAuthenticatedUserResolver::class)->resolve($request->user());

        return $user instanceof User && $user->hasMemberMcpAccess();
    }
}
