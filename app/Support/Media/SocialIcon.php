<?php

declare(strict_types=1);

namespace App\Support\Media;

use Illuminate\Support\Facades\Storage;

/**
 * Resolves the public URL for a bundled social-media icon.
 *
 * Icons are served from the S3 bucket (public `social-media-icons/*` prefix).
 * Environments without an S3 bucket configured fall back to the local
 * `public/storage` link so tests and local setups keep working.
 */
final class SocialIcon
{
    public static function url(string $file): string
    {
        if (blank(config('filesystems.disks.s3.bucket'))) {
            return asset('storage/social-media-icons/'.$file);
        }

        return Storage::disk('s3')->url('social-media-icons/'.$file);
    }
}
