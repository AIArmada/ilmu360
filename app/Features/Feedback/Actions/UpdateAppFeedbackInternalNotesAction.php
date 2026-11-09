<?php

declare(strict_types=1);

namespace App\Features\Feedback\Actions;

use App\Features\Feedback\Models\AppFeedback;
use App\Shared\Auth\AccountActor;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

class UpdateAppFeedbackInternalNotesAction
{
    use AsAction;

    public function handle(AccountActor $actor, AppFeedback $feedback, ?string $internalNotes): AppFeedback
    {
        Gate::forUser($actor)->authorize('updateInternalNotes', $feedback);

        $feedback->forceFill([
            'internal_notes' => filled($internalNotes) ? trim((string) $internalNotes) : null,
        ]);
        $feedback->save();

        return $feedback;
    }
}
