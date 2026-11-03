<?php

declare(strict_types=1);

namespace App\Actions\AppFeedback;

use App\Enums\AppFeedbackStatus;
use App\Models\AppFeedback;
use App\Models\AppFeedbackMessage;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

class PostAppFeedbackMessageAction
{
    use AsAction;

    public function handle(User $author, AppFeedback $feedback, string $body): AppFeedbackMessage
    {
        Gate::forUser($author)->authorize('reply', $feedback);

        if (! in_array($feedback->status, [AppFeedbackStatus::New, AppFeedbackStatus::Triaged], true)) {
            throw ValidationException::withMessages([
                'body' => __('app-feedback.validation.invalid_transition'),
            ]);
        }

        $body = trim($body);

        /** @var array{body: string} $validated */
        $validated = Validator::make(['body' => $body], [
            'body' => ['required', 'string', 'min:3', 'max:2000'],
        ])->validate();

        $message = new AppFeedbackMessage;
        $message->fill([
            'author_role' => $author->hasGlobalAdminAccess() ? 'maintainer' : 'reporter',
            'visibility' => 'reporter',
            'body' => $validated['body'],
        ]);
        $message->forceFill([
            'feedback_id' => $feedback->getKey(),
            'author_id' => $author->getKey(),
        ]);
        $message->save();

        return $message;
    }
}
