<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Member;

use App\Models\User;
use App\Support\Mcp\McpAuthenticatedUserResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Support\ValidationMessages;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

abstract class AbstractMemberTool extends Tool
{
    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        $tool = parent::toArray();
        $securitySchemes = $this->securitySchemes();
        $annotations = $this->normalizedAnnotations(
            is_array($tool['annotations'] ?? null) ? $tool['annotations'] : [],
        );

        $tool['annotations'] = $annotations === [] ? (object) [] : $annotations;
        $tool['securitySchemes'] = $securitySchemes;

        $existingMeta = is_array($tool['_meta'] ?? null) ? $tool['_meta'] : [];
        $invocationDefaults = isset($existingMeta['openai/toolInvocation/invoking'])
            ? []
            : $this->defaultToolInvocationMessages();

        $tool['_meta'] = array_merge(
            $invocationDefaults,
            $existingMeta,
            ['securitySchemes' => $securitySchemes],
        );

        return $tool;
    }

    /**
     * Returns the default toolInvocation progress messages for ChatGPT.
     * Individual tools override this by calling setMeta() in their constructor.
     *
     * @return array{'openai/toolInvocation/invoking': string, 'openai/toolInvocation/invoked': string}
     */
    protected function defaultToolInvocationMessages(): array
    {
        return match (true) {
            str_starts_with($this->name, 'member-list-') => [
                'openai/toolInvocation/invoking' => 'Fetching list…',
                'openai/toolInvocation/invoked' => 'List ready.',
            ],
            str_starts_with($this->name, 'member-get-') => [
                'openai/toolInvocation/invoking' => 'Loading…',
                'openai/toolInvocation/invoked' => 'Done.',
            ],
            str_starts_with($this->name, 'member-create-') => [
                'openai/toolInvocation/invoking' => 'Creating record…',
                'openai/toolInvocation/invoked' => 'Record created.',
            ],
            str_starts_with($this->name, 'member-update-') => [
                'openai/toolInvocation/invoking' => 'Saving changes…',
                'openai/toolInvocation/invoked' => 'Changes saved.',
            ],
            str_starts_with($this->name, 'member-upload-') => [
                'openai/toolInvocation/invoking' => 'Uploading image…',
                'openai/toolInvocation/invoked' => 'Image uploaded.',
            ],
            str_starts_with($this->name, 'member-approve-') => [
                'openai/toolInvocation/invoking' => 'Submitting approval…',
                'openai/toolInvocation/invoked' => 'Approval submitted.',
            ],
            str_starts_with($this->name, 'member-reject-') => [
                'openai/toolInvocation/invoking' => 'Submitting rejection…',
                'openai/toolInvocation/invoked' => 'Rejection submitted.',
            ],
            str_starts_with($this->name, 'member-cancel-') => [
                'openai/toolInvocation/invoking' => 'Cancelling…',
                'openai/toolInvocation/invoked' => 'Cancelled.',
            ],
            str_starts_with($this->name, 'member-submit-') => [
                'openai/toolInvocation/invoking' => 'Submitting…',
                'openai/toolInvocation/invoked' => 'Submitted.',
            ],
            default => [
                'openai/toolInvocation/invoking' => 'Working…',
                'openai/toolInvocation/invoked' => 'Done.',
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    protected function validateArguments(Request $request, array $rules): array
    {
        $this->rejectUnexpectedArguments($request, array_keys($rules));

        return $request->validate($rules);
    }

    protected function authorizeMember(Request $request): User
    {
        $user = app(McpAuthenticatedUserResolver::class)->resolve($request->user());

        abort_unless($user instanceof User && $user->hasMemberMcpAccess(), 403);

        auth()->setUser($user);

        return $user;
    }

    /**
     * @param  callable(): array<string, mixed>  $callback
     */
    protected function structuredResponse(callable $callback): ResponseFactory|Response
    {
        return $this->safeResponse(fn (): ResponseFactory => Response::structured($callback()));
    }

    /**
     * @param  callable(): (ResponseFactory|Response)  $callback
     */
    protected function safeResponse(callable $callback): ResponseFactory|Response
    {
        try {
            return $callback();
        } catch (ValidationException $exception) {
            return $this->errorResponse(
                ValidationMessages::from($exception),
                'validation_error',
                ['errors' => $exception->errors()],
            );
        } catch (HttpExceptionInterface $exception) {
            return $this->errorResponse(
                $this->httpExceptionMessage($exception),
                $this->httpExceptionCode($exception),
                ['status' => $exception->getStatusCode()],
            );
        } catch (ModelNotFoundException) {
            return $this->errorResponse(
                'Resource not found.',
                'not_found',
                ['status' => 404],
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->errorResponse('Unexpected server error.', 'server_error');
        }
    }

    protected function httpExceptionMessage(HttpExceptionInterface $exception): string
    {
        return match ($exception->getStatusCode()) {
            401 => 'Unauthenticated.',
            403 => 'Forbidden.',
            404 => 'Resource not found.',
            422 => $exception->getMessage() !== '' ? $exception->getMessage() : 'The given data was invalid.',
            default => $exception->getMessage() !== '' ? $exception->getMessage() : 'Request failed.',
        };
    }

    protected function httpExceptionCode(HttpExceptionInterface $exception): string
    {
        return match ($exception->getStatusCode()) {
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'not_found',
            422 => 'invalid_request',
            default => 'request_failed',
        };
    }

    /**
     * @param  list<string>  $allowedKeys
     */
    protected function rejectUnexpectedArguments(Request $request, array $allowedKeys): void
    {
        $unexpected = array_values(array_diff(array_keys($request->all()), $allowedKeys));

        if ($unexpected === []) {
            return;
        }

        throw ValidationException::withMessages([
            'arguments' => [
                'Unexpected argument(s): '.implode(', ', $unexpected).'.',
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    protected function errorResponse(string $message, string $code, array $details = []): ResponseFactory
    {
        $structured = [
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];

        if ($details !== []) {
            $structured['error']['details'] = $details;
        }

        return Response::make(Response::error($message))
            ->withStructuredContent($structured);
    }

    /**
     * @return list<array{type: string, scopes: list<string>}>
     */
    protected function securitySchemes(): array
    {
        return [
            [
                'type' => 'oauth2',
                'scopes' => ['mcp:use'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $annotations
     * @return array<string, mixed>
     */
    private function normalizedAnnotations(array $annotations): array
    {
        if (($annotations['readOnlyHint'] ?? false) === true) {
            $annotations['destructiveHint'] = false;
            $annotations['openWorldHint'] = false;
        }

        return $annotations;
    }
}
