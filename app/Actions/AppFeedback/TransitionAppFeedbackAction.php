<?php

declare(strict_types=1);

namespace App\Actions\AppFeedback;

use App\Enums\AppFeedbackStatus;
use App\Models\AppFeedback;
use App\Notifications\AppFeedbackClosedNotification;
use App\Notifications\AppFeedbackTriagedNotification;
use App\Shared\Auth\AccountActor;
use App\Shared\Auth\AccountDirectory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

class TransitionAppFeedbackAction
{
    use AsAction;

    public function __construct(
        private readonly AccountDirectory $accounts,
    ) {}

    public function handle(
        AccountActor $actor,
        AppFeedback $feedback,
        AppFeedbackStatus $target,
        ?string $resolutionNote = null,
    ): AppFeedback {
        Gate::forUser($actor)->authorize('transition', $feedback);

        $resolutionNote = is_string($resolutionNote) ? trim($resolutionNote) : null;
        $resolutionNote = $resolutionNote !== '' ? $resolutionNote : null;

        if ($target->isTerminal() && $resolutionNote === null) {
            throw ValidationException::withMessages([
                'resolution_note' => __('app-feedback.validation.resolution_note_required'),
            ]);
        }

        if ($resolutionNote !== null && mb_strlen($resolutionNote) > 2000) {
            throw ValidationException::withMessages([
                'resolution_note' => __('app-feedback.validation.resolution_note_max'),
            ]);
        }

        [$feedback, $changed] = DB::transaction(function () use ($actor, $feedback, $target, $resolutionNote): array {
            $feedback = AppFeedback::query()
                ->lockForUpdate()
                ->findOrFail($feedback->getKey());

            $current = $feedback->status;

            if ($current === $target) {
                if ($target->isTerminal() && $feedback->resolution_note !== $resolutionNote) {
                    throw ValidationException::withMessages([
                        'resolution_note' => __('app-feedback.validation.resolution_note_locked'),
                    ]);
                }

                return [$feedback, false];
            }

            $timestampColumn = match ([$current, $target]) {
                [AppFeedbackStatus::New, AppFeedbackStatus::Triaged] => 'triaged_at',
                [AppFeedbackStatus::Triaged, AppFeedbackStatus::Resolved] => 'resolved_at',
                [AppFeedbackStatus::Triaged, AppFeedbackStatus::Declined] => 'declined_at',
                default => throw ValidationException::withMessages([
                    'status' => __('app-feedback.validation.invalid_transition'),
                ]),
            };

            $feedback->forceFill([
                'status' => $target,
                $timestampColumn => now(),
                'last_state_change_at' => now(),
                'handled_by' => $actor->actorId(),
                'resolution_note' => $target->isTerminal() ? $resolutionNote : null,
            ]);
            $feedback->save();

            return [$feedback, true];
        });

        if ($changed && $feedback->status->isTerminal()) {
            $this->notifyReporter($feedback);
        }

        if ($changed && $feedback->status === AppFeedbackStatus::Triaged) {
            $this->notifyTriaged($feedback);
        }

        return $feedback->fresh(['reporter', 'handler']) ?? $feedback;
    }

    private function notifyTriaged(AppFeedback $feedback): void
    {
        $reporter = $this->accounts->resolveNotifiable((string) $feedback->reporter_id);

        if ($reporter === null) {
            return;
        }

        try {
            Notification::send($reporter, new AppFeedbackTriagedNotification($feedback));
        } catch (Throwable $exception) {
            Log::warning('Unable to send app feedback triage notification.', [
                'feedback_id' => (string) $feedback->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function notifyReporter(AppFeedback $feedback): void
    {
        $reporter = $this->accounts->resolveNotifiable((string) $feedback->reporter_id);

        if ($reporter === null) {
            return;
        }

        try {
            Notification::send($reporter, new AppFeedbackClosedNotification($feedback));
        } catch (Throwable $exception) {
            Log::warning('Unable to send app feedback closure notification.', [
                'feedback_id' => (string) $feedback->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
