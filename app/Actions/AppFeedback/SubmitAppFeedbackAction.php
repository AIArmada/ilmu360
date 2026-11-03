<?php

declare(strict_types=1);

namespace App\Actions\AppFeedback;

use App\Enums\AppFeedbackStatus;
use App\Models\AppFeedback;
use App\Models\User;
use App\Notifications\AppFeedbackReceivedNotification;
use App\Notifications\AppFeedbackSubmittedNotification;
use App\Services\Signals\ProductSignalsService;
use App\Support\AppFeedback\AppFeedbackInput;
use App\Support\Media\ModelMediaSyncService;
use Illuminate\Database\Eloquent\Builder;
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
        private readonly ModelMediaSyncService $mediaSyncService,
        private readonly ProductSignalsService $productSignalsService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $user, array $data, Request $request): AppFeedback
    {
        Gate::forUser($user)->authorize('create', AppFeedback::class);

        $data['title'] = is_string($data['title'] ?? null) ? trim($data['title']) : ($data['title'] ?? null);
        $data['description'] = is_string($data['description'] ?? null) ? trim($data['description']) : ($data['description'] ?? null);

        $maxUploadSizeKb = (int) ceil(((int) config('media-library.max_file_size', 10 * 1024 * 1024)) / 1024);

        /** @var array<string, mixed> $validated */
        $validated = Validator::make($data, AppFeedbackInput::rules($maxUploadSizeKb))->validate();

        $input = AppFeedbackInput::fromValidated($validated, $request);
        $payloadHash = $input->payloadHash();

        [$feedback, $created] = DB::transaction(function () use ($user, $input, $payloadHash): array {
            User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            $existing = AppFeedback::query()
                ->where('reporter_id', $user->getKey())
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
                ->where('reporter_id', $user->getKey())
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
                'reporter_id' => $user->getKey(),
                'submission_key' => $input->submissionKey,
                'payload_hash' => $payloadHash,
                'status' => AppFeedbackStatus::New,
                'last_state_change_at' => now(),
            ]);
            $feedback->save();

            try {
                $this->mediaSyncService->syncMultiple($feedback, $input->evidence, 'evidence');
            } catch (Throwable $exception) {
                $feedback->clearMediaCollection('evidence');

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

    private function notify(AppFeedback $feedback, User $reporter): void
    {
        try {
            Notification::send($reporter, new AppFeedbackReceivedNotification($feedback));

            $maintainers = User::query()
                ->whereHas('globalRoles', static function (Builder $query): void {
                    $query->whereIn('name', ['super_admin', 'admin']);
                })
                ->get();

            if ($maintainers->isNotEmpty()) {
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
            $this->productSignalsService->recordAppFeedbackSubmitted($feedback);
        } catch (Throwable $exception) {
            Log::warning('Unable to record app feedback signal.', [
                'feedback_id' => (string) $feedback->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
