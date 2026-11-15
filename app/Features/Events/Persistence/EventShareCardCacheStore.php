<?php

declare(strict_types=1);

namespace App\Features\Events\Persistence;

use App\Features\Events\Api\EventShareCardCache;
use App\Features\Events\Api\ShareCardCachePruneResult;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

final readonly class EventShareCardCacheStore implements EventShareCardCache
{
    private const string DIRECTORY = 'share-cards';

    private const string FILE_PREFIX = 'share-card-';

    private const string PNG_EXTENSION = '.png';

    /**
     * @throws InvalidArgumentException
     */
    public function read(string $revision): ?string
    {
        $disk = Storage::disk((string) config('share-cards.disk', 'public'));
        $path = $this->pathForRevision($revision);

        if (! $disk->exists($path)) {
            return null;
        }

        $bytes = $disk->get($path);

        if (is_string($bytes) && $bytes !== '' && str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return $bytes;
        }

        $disk->delete($path);

        return null;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function publish(string $revision, string $bytes): void
    {
        $disk = Storage::disk((string) config('share-cards.disk', 'public'));
        $path = $this->pathForRevision($revision);
        $temporaryPath = $path.'.tmp-'.getmypid();

        try {
            $disk->put($temporaryPath, $bytes);
            $disk->move($temporaryPath, $path);
        } finally {
            try {
                $disk->delete($temporaryPath);
            } catch (Throwable) {
                // A stale temp file is eligible for pruning if cleanup fails.
            }
        }
    }

    public function pruneOlderThan(int $thresholdTimestamp, bool $dryRun): ShareCardCachePruneResult
    {
        $disk = Storage::disk((string) config('share-cards.disk', 'public'));
        $files = [];

        foreach ($disk->files(self::DIRECTORY) as $file) {
            $isCard = str_ends_with($file, self::PNG_EXTENSION);
            $isStaleTemporaryFile = str_contains($file, '.tmp-');

            if (! $isCard && ! $isStaleTemporaryFile) {
                continue;
            }

            if ($disk->lastModified($file) > $thresholdTimestamp) {
                continue;
            }

            if (! $dryRun) {
                $disk->delete($file);
            }

            $files[] = $file;
        }

        return new ShareCardCachePruneResult($files);
    }

    private function pathForRevision(string $revision): string
    {
        if (preg_match('/\A[a-f0-9]{16}\z/', $revision) !== 1) {
            throw new InvalidArgumentException('Share-card revisions must be 16 lowercase hexadecimal characters.');
        }

        return self::DIRECTORY.'/'.self::FILE_PREFIX.$revision.self::PNG_EXTENSION;
    }
}
