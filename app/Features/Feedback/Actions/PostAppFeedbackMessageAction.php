<?php

declare(strict_types=1);

namespace App\Features\Feedback\Actions;

use App\Features\Feedback\Enums\AppFeedbackStatus;
use App\Features\Feedback\Models\AppFeedback;
use App\Features\Feedback\Models\AppFeedbackMessage;
use App\Features\Feedback\Notifications\AppFeedbackRepliedNotification;
use App\Shared\Auth\AccountActor;
use App\Shared\Auth\AccountDirectory;
use Illuminate\Support\Facades\DB;
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

    public function __construct(
        private readonly AccountDirectory $accounts,
    ) {}

    public function handle(AccountActor $author, AppFeedback $feedback, string $body): AppFeedbackMessage
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

        /** @var array{0: AppFeedbackMessage, 1: AppFeedback} $result */
        $result = DB::transaction(function () use ($author, $feedback, $validated): array {
            $lockedFeedback = AppFeedback::query()
                ->lockForUpdate()
                ->findOrFail($feedback->getKey());

            if (! in_array($lockedFeedback->status, [AppFeedbackStatus::New, AppFeedbackStatus::Triaged], true)) {
                throw ValidationException::withMessages([
                    'body' => __('app-feedback.validation.invalid_transition'),
                ]);
            }

            $message = new AppFeedbackMessage;
            $message->fill([
                'author_role' => $author->hasGlobalAdminAccess() ? 'maintainer' : 'reporter',
                'visibility' => 'reporter',
                'body' => $validated['body'],
            ]);
            $message->forceFill([
                'feedback_id' => $lockedFeedback->getKey(),
                'author_id' => $author->actorId(),
            ]);
            $message->save();

            return [$message, $lockedFeedback];
        });

        [$message, $lockedFeedback] = $result;

        $this->notifyReporterOfReply($lockedFeedback, $message, $author);

        return $message;
    }

    private function notifyReporterOfReply(AppFeedback $feedback, AppFeedbackMessage $message, AccountActor $author): void
    {
        if ($message->author_role !== 'maintainer' || $message->visibility !== 'reporter') {
            return;
        }

        $reporter = $this->accounts->resolveNotifiable((string) $feedback->reporter_id);

        if ($reporter === null || (string) $reporter->getAuthIdentifier() === $author->actorId()) {
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
