<?php

declare(strict_types=1);

namespace App\Actions\AppFeedback;

use App\Enums\AppFeedbackStatus;
use App\Models\AppFeedback;
use App\Models\AppFeedbackMessage;
use App\Models\User;
use App\Notifications\AppFeedbackRepliedNotification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

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

        $this->notifyReporterOfReply($feedback, $message, $author);

        return $message;
    }

    private function notifyReporterOfReply(AppFeedback $feedback, AppFeedbackMessage $message, User $author): void
    {
        if ($message->author_role !== 'maintainer' || $message->visibility !== 'reporter') {
            return;
        }

        $reporter = $feedback->reporter;

        if (! $reporter instanceof User || $reporter->getKey() === $author->getKey()) {
            return;
        }

        try {
            Notification::send($reporter, new AppFeedbackRepliedNotification($feedback, $message));
        } catch (Throwable $exception) {
            Log::warning('Unable to send app feedback reply notification.', [
                'feedback_id' => (string) $feedback->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
