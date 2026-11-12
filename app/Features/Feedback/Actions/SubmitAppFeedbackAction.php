<?php

declare(strict_types=1);

namespace App\Features\Feedback\Actions;

use App\Features\Feedback\Enums\AppFeedbackCategory;
use App\Features\Feedback\Enums\AppFeedbackStatus;
use App\Features\Feedback\Models\AppFeedback;
use App\Features\Feedback\Notifications\AppFeedbackReceivedNotification;
use App\Features\Feedback\Notifications\AppFeedbackSubmittedNotification;
use App\Features\Feedback\Support\AppFeedbackInput;
use App\Platform\Media\MediaCollectionSync;
use App\Platform\Media\MediaEvidence;
use App\Shared\Auth\AccountActor;
use App\Shared\Auth\AccountDirectory;
use App\Shared\Signals\SignalRecorder;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

class SubmitAppFeedbackAction
{
    use AsAction;

    public function __construct(
        private readonly MediaCollectionSync $mediaCollectionSync,
        private readonly SignalRecorder $signals,
        private readonly AccountDirectory $accounts,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(AccountActor $user, array $data, Request $request): AppFeedback
    {
        Gate::forUser($user)->authorize('create', AppFeedback::class);

        $data['title'] = is_string($data['title'] ?? null) ? trim($data['title']) : ($data['title'] ?? null);
        $data['description'] = is_string($data['description'] ?? null) ? trim($data['description']) : ($data['description'] ?? null);

        $maxUploadSizeKb = (int) ceil(((int) config('media-library.max_file_size', 10 * 1024 * 1024)) / 1024);

        /** @var array<string, mixed> $validated */
        $validated = Validator::make($data, AppFeedbackInput::rules($maxUploadSizeKb))->validate();

        $this->rejectLowEffortContent($validated);

        $input = AppFeedbackInput::fromValidated($validated, $request);
        $payloadHash = $input->payloadHash();

        [$feedback, $created] = DB::transaction(function () use ($user, $input, $payloadHash): array {
            $this->accounts->lockForUpdate($user->actorId());

            $existing = AppFeedback::query()
                ->where('reporter_id', $user->actorId())
                ->where('submission_key', $input->submissionKey)
                ->first();

            if ($existing instanceof AppFeedback) {
                if (! hash_equals((string) $existing->payload_hash, $payloadHash)) {
                    throw ValidationException::withMessages([
                        'submission_key' => __('app-feedback.validation.submission_changed'),
                    ]);
                }

                return [$existing, false];
            }

            $recentCount = AppFeedback::query()
                ->where('reporter_id', $user->actorId())
                ->where('created_at', '>=', now()->subHour())
                ->count();

            if ($recentCount >= 3) {
                throw ValidationException::withMessages([
                    'quota' => __('app-feedback.validation.quota_exceeded'),
                ]);
            }

            $feedback = new AppFeedback;
            $feedback->fill([
                'category' => $input->category,
                'title' => $input->title,
                'description' => $input->description,
                'page_url' => $input->pageUrl,
                'diagnostics' => $input->diagnostics(),
                'details' => $input->details === [] ? null : $input->details,
            ]);
            $feedback->forceFill([
                'reporter_id' => $user->actorId(),
                'submission_key' => $input->submissionKey,
                'payload_hash' => $payloadHash,
                'status' => AppFeedbackStatus::New,
                'last_state_change_at' => now(),
            ]);
            $feedback->save();

            try {
                $this->mediaCollectionSync->syncMultiple($feedback, $input->evidence, MediaEvidence::COLLECTION);
            } catch (Throwable $exception) {
                $this->mediaCollectionSync->clearCollection($feedback, MediaEvidence::COLLECTION);

                throw $exception;
            }

            return [$feedback, true];
        });

        if ($created) {
            $this->notify($feedback, $user);
            $this->recordSignal($feedback);
        }

        return $feedback;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function rejectLowEffortContent(array $validated): void
    {
        $normalize = static function (?string $value): string {
            $value = mb_strtolower(trim((string) $value));
            $value = (string) preg_replace('/[^\p{L}\p{N}\s]/u', '', $value);
            $value = (string) preg_replace('/\s+/u', ' ', $value);

            return trim($value);
        };

        $title = $normalize(is_string($validated['title'] ?? null) ? $validated['title'] : null);
        $description = $normalize(is_string($validated['description'] ?? null) ? $validated['description'] : null);

        if ($title !== '' && $description !== '' && $description === $title) {
            throw ValidationException::withMessages([
                'description' => __('app-feedback.validation.description_repeats_title'),
            ]);
        }

        if (($validated['category'] ?? null) === AppFeedbackCategory::Bug->value) {
            $words = $description === '' ? [] : preg_split('/\s+/u', $description);

            if (! is_array($words) || count($words) < 4) {
                throw ValidationException::withMessages([
                    'description' => __('app-feedback.validation.description_too_brief'),
                ]);
            }
        }
    }

    private function notify(AppFeedback $feedback, AccountActor $reporter): void
    {
        try {
            $recipient = $this->accounts->resolveNotifiable($reporter->actorId());

            if ($recipient instanceof Authenticatable) {
                Notification::send($recipient, new AppFeedbackReceivedNotification($feedback));
            }

            $maintainers = $this->accounts->maintainers();

            if ($maintainers !== []) {
                Notification::send($maintainers, new AppFeedbackSubmittedNotification($feedback));
            }
        } catch (Throwable $exception) {
            Log::warning('Unable to send app feedback notifications.', [
                'feedback_id' => (string) $feedback->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function recordSignal(AppFeedback $feedback): void
    {
        try {
            $this->signals->record(
                eventName: 'app_feedback.submitted',
                eventCategory: 'feedback',
                actorId: (string) $feedback->reporter_id,
                properties: [
                    'category' => $feedback->category->value,
                    'status' => $feedback->status->value,
                    'has_evidence' => $feedback->getMedia('evidence')->isNotEmpty(),
                    'details_keys' => is_array($feedback->details) ? array_keys($feedback->details) : [],
                    'has_page_url' => is_string($feedback->page_url) && $feedback->page_url !== '',
                ],
            );
        } catch (Throwable $exception) {
            Log::warning('Unable to record app feedback signal.', [
                'feedback_id' => (string) $feedback->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
