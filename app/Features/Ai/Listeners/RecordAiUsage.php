<?php

declare(strict_types=1);

namespace App\Features\Ai\Listeners;

use App\Features\Ai\Actions\RecordAiUsageAction;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\AudioGenerated;
use Laravel\Ai\Events\EmbeddingsGenerated;
use Laravel\Ai\Events\ImageGenerated;
use Laravel\Ai\Events\Reranked;
use Laravel\Ai\Events\TranscriptionGenerated;

class RecordAiUsage
{
    public function __construct(protected RecordAiUsageAction $recordAiUsage) {}

    public function handle(
        AgentPrompted|AgentStreamed|ImageGenerated|TranscriptionGenerated|EmbeddingsGenerated|Reranked|AudioGenerated $event
    ): void {
        $this->recordAiUsage->handle($event);
    }
}
