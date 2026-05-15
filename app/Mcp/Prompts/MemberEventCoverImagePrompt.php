<?php

declare(strict_types=1);

namespace App\Mcp\Prompts;

use App\Mcp\Prompts\Concerns\BuildsEventImagePrompt;
use App\Models\Event;
use App\Models\User;
use App\Support\Api\Member\MemberResourceRegistry;
use App\Support\Mcp\McpAuthenticatedUserResolver;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

#[Name('member-event-cover-image-prompt')]
#[Title('Event Cover Image Prompt')]
#[Description('Builds a 16:9 cover image prompt for an accessible Ahli event with the event data and brand reference images attached. Use the returned prompt and images with ChatGPT image generation, then upload the result with member-upload-event-cover-image.')]
class MemberEventCoverImagePrompt extends Prompt
{
    use BuildsEventImagePrompt;

    public function __construct(
        private readonly MemberResourceRegistry $registry,
    ) {
        //
    }

    /**
     * @return array<int, Response>
     */
    public function handle(Request $request): array
    {
        $user = app(McpAuthenticatedUserResolver::class)->resolve($request->user());

        abort_unless($user instanceof User && $user->hasMemberMcpAccess(), 403);

        auth()->setUser($user);

        $arguments = $request->all();
        $eventKey = is_string($arguments['event_key'] ?? null) ? trim($arguments['event_key']) : '';

        abort_if($eventKey === '', 422);

        $event = $this->resolveEvent($eventKey);

        abort_unless($event instanceof Event, 404);

        return $this->buildEventImagePromptMessages($event, 'cover', $arguments);
    }

    /**
     * @return array<int, Argument>
     */
    #[\Override]
    public function arguments(): array
    {
        return $this->eventImagePromptArguments();
    }

    private function resolveEvent(string $eventKey): ?Event
    {
        $resourceClass = $this->registry->resolve('events');

        if (! is_string($resourceClass) || ! $this->registry->canAccessResource($resourceClass)) {
            return null;
        }

        $query = $this->registry->queryFor($resourceClass);
        $model = $query->getModel();

        $record = $query
            ->where(function (Builder $query) use ($model, $eventKey): void {
                $query
                    ->where($model->qualifyColumn($model->getRouteKeyName()), $eventKey)
                    ->orWhere($model->qualifyColumn('slug'), $eventKey);
            })
            ->first();

        return $record instanceof Event ? $record : null;
    }
}
