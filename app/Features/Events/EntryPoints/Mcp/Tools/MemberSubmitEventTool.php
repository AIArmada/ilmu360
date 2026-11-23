<?php

declare(strict_types=1);

namespace App\Features\Events\EntryPoints\Mcp\Tools;

use App\Features\Events\EntryPoints\Http\SubmitEventRequestHandler;
use App\Platform\Mcp\McpFilePayloadNormalizer;
use App\Platform\Mcp\Tools\Concerns\DefinesMcpFileDescriptorSchema;
use App\Platform\Media\MediaCollectionSync;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Spatie\MediaLibrary\HasMedia;

#[IsReadOnly(false)]
#[IsIdempotent(false)]
#[IsDestructive(false)]
#[IsOpenWorld(true)]
final class MemberSubmitEventTool extends AbstractMemberEventSubmissionTool
{
    use DefinesMcpFileDescriptorSchema;

    #[\Override]
    protected string $name = 'member-submit-event';

    #[\Override]
    protected string $title = 'Submit Event';

    #[\Override]
    protected string $description = 'Submit one event using the ordinary event submission workflow. First validate with member-validate-event-submission and show the schedule to the user. Call after the user confirms. Returns the submission, event and occurrence IDs and their review state.';

    public function __construct(
        private readonly SubmitEventRequestHandler $submissions,
        private readonly McpFilePayloadNormalizer $files,
        private readonly MediaCollectionSync $media,
    ) {}

    public function handle(Request $request, HttpRequest $httpRequest): ResponseFactory|Response
    {
        return $this->structuredResponse(function () use ($request, $httpRequest): array {
            $actor = $this->authorizeMember($request);
            $state = $this->submissionArguments($request, [
                'captcha_token' => ['sometimes', 'string', 'max:2048'],
                'cover' => ['sometimes', 'array'],
                'poster' => ['sometimes', 'array'],
            ]);

            $key = 'mcp:event-submission:'.$actor->getKey();
            abort_if(RateLimiter::tooManyAttempts($key, 5), 429, 'Event submission rate limit reached. Try again later.');
            RateLimiter::hit($key, 3600);

            $contract = ['type' => 'file', 'accepted_mime_types' => ['image/jpeg', 'image/png', 'image/webp']];
            $normalized = $this->files->normalize($state, ['cover' => $contract, 'poster' => $contract]);

            try {
                $maxSize = (int) ceil(((int) config('media-library.max_file_size', 10 * 1024 * 1024)) / 1024);
                $files = Validator::make($normalized['payload'], [
                    'cover' => ['sometimes', 'required', 'image', 'mimes:jpg,jpeg,png,webp', 'dimensions:ratio=16/9', 'max:'.$maxSize],
                    'poster' => ['sometimes', 'required', 'image', 'mimes:jpg,jpeg,png,webp', 'dimensions:ratio=3/4', 'max:'.$maxSize],
                ])->validate();

                unset($state['cover'], $state['poster']);
                $result = $this->submissions->handle(
                    state: $state,
                    request: $httpRequest,
                    submitter: $actor,
                    scopedInstitutionId: $state['scoped_institution_id'] ?? null,
                    persistRelationships: function (HasMedia $model) use ($files): void {
                        foreach (['cover', 'poster'] as $collection) {
                            $file = $files[$collection] ?? null;

                            if ($file instanceof UploadedFile) {
                                $this->media->syncSingle($model, $file, $collection);
                            }
                        }
                    },
                );
            } finally {
                $this->files->cleanup($normalized['temporary_paths']);
            }

            $event = $result['event'];
            $occurrence = $result['occurrence'];
            $submission = $result['submission'];

            return ['data' => [
                'submission_id' => (string) $submission->getKey(),
                'submission_status' => (string) $submission->status,
                'event_id' => (string) $event->getKey(),
                'event_slug' => $event->slug,
                'event_status' => (string) $event->status,
                'occurrence_id' => $occurrence?->getKey(),
                'occurrence_status' => $occurrence === null ? null : (string) $occurrence->status,
                'starts_at' => $occurrence?->starts_at?->toIso8601String(),
                'ends_at' => $occurrence?->ends_at?->toIso8601String(),
                'visibility' => $result['visibility'],
                'auto_approved' => $result['auto_approved'],
                'occurrence_pending_review' => $result['occurrence_pending_review'],
            ]];
        });
    }

    /** @return array<string, Type> */
    #[\Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            ...parent::schema($schema),
            'captcha_token' => $schema->string()->max(2048)->description('Single-use CAPTCHA token when Turnstile verification is enabled.'),
            'cover' => $this->fileDescriptorSchema($schema)->description('Optional 16:9 website and app image.'),
            'poster' => $this->fileDescriptorSchema($schema)->description('Optional 3:4 portrait flyer.'),
        ];
    }

    /** @return array<string, Type> */
    #[\Override]
    public function outputSchema(JsonSchema $schema): array
    {
        return ['data' => $schema->object([
            'submission_id' => $schema->string()->format('uuid')->required(),
            'submission_status' => $schema->string()->required(),
            'event_id' => $schema->string()->format('uuid')->required(),
            'event_slug' => $schema->string()->required(),
            'event_status' => $schema->string()->required(),
            'occurrence_id' => $schema->string()->format('uuid')->nullable()->required(),
            'occurrence_status' => $schema->string()->nullable()->required(),
            'starts_at' => $schema->string()->format('date-time')->nullable()->required(),
            'ends_at' => $schema->string()->format('date-time')->nullable()->required(),
            'visibility' => $schema->string()->required(),
            'auto_approved' => $schema->boolean()->required(),
            'occurrence_pending_review' => $schema->boolean()->required(),
        ])->required()];
    }
}
