<?php

declare(strict_types=1);

namespace App\Features\Feedback\EntryPoints\Http;

use App\Features\Feedback\Models\AppFeedback;
use App\Platform\Media\MediaEvidence;
use App\Shared\Auth\AccountActor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AppFeedbackEvidenceController
{
    public function __invoke(Request $request, AppFeedback $feedback, string $mediaId): StreamedResponse
    {
        $user = auth()->user();

        abort_unless(
            $user instanceof AccountActor
                && ($feedback->reporter_id === $user->actorId() || $user->hasGlobalAdminAccess()),
            404,
        );

        Gate::authorize('downloadEvidence', $feedback);

        abort_unless(ctype_digit($mediaId), 404);

        $media = $feedback->media()
            ->whereKey($mediaId)
            ->where('collection_name', MediaEvidence::COLLECTION)
            ->firstOrFail();

        abort_unless($media->disk === MediaEvidence::PRIVATE_DISK, 404);

        if ($request->query('preview') && str_starts_with((string) $media->mime_type, 'image/')) {
            $path = $media->hasGeneratedConversion(MediaEvidence::THUMB_CONVERSION)
                ? $media->getPath(MediaEvidence::THUMB_CONVERSION)
                : $media->getPath();

            abort_unless(is_string($path) && $path !== '' && is_file($path), 404);

            return Storage::disk(MediaEvidence::PRIVATE_DISK)->response(
                $media->hasGeneratedConversion(MediaEvidence::THUMB_CONVERSION)
                    ? $media->getPathRelativeToRoot(MediaEvidence::THUMB_CONVERSION)
                    : $media->getPathRelativeToRoot(),
                $media->file_name,
                [
                    'Cache-Control' => 'private, no-store',
                    'X-Content-Type-Options' => 'nosniff',
                ],
            );
        }

        return Storage::disk(MediaEvidence::PRIVATE_DISK)->download(
            $media->getPathRelativeToRoot(),
            $media->file_name,
            [
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
