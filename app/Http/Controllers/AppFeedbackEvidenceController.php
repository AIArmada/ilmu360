<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AppFeedback;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AppFeedbackEvidenceController extends Controller
{
    public function __invoke(AppFeedback $feedback, string $mediaId): StreamedResponse
    {
        $user = auth()->user();

        abort_unless(
            $user instanceof User
                && ($feedback->reporter_id === $user->getKey() || $user->hasGlobalAdminAccess()),
            404,
        );

        Gate::authorize('downloadEvidence', $feedback);

        abort_unless(ctype_digit($mediaId), 404);

        $media = $feedback->media()
            ->whereKey($mediaId)
            ->where('collection_name', 'evidence')
            ->firstOrFail();

        abort_unless($media->disk === 'app_feedback_private', 404);

        return Storage::disk('app_feedback_private')->download(
            $media->getPathRelativeToRoot(),
            $media->file_name,
            [
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
