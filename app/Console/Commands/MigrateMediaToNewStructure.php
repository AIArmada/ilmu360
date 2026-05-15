<?php

namespace App\Console\Commands;

use App\Support\Media\MediaFileNamer;
use App\Support\Media\MediaPathGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MigrateMediaToNewStructure extends Command
{
    protected $signature = 'media:migrate-structure
                            {--dry-run : Preview changes without moving files}
                            {--force : Run without confirmation}';

    protected $description = 'Migrate existing media files to the new organized directory structure';

    public function handle(): int
    {
        $pathGenerator = new MediaPathGenerator;
        $media = Media::with('model')->get();
        $dryRun = $this->option('dry-run');

        if ($media->isEmpty()) {
            $this->info('No media records found. Nothing to migrate.');

            return self::SUCCESS;
        }

        $this->info("Found {$media->count()} media record(s) to process.");

        if ($dryRun) {
            $this->warn('DRY RUN — no files will be moved.');
        }

        $rows = [];
        $migrationPlan = [];

        foreach ($media as $item) {
            $disk = $item->disk;
            $storage = Storage::disk($disk);

            // Current path: {media_id}/{filename}
            $currentPath = "{$item->id}/{$item->file_name}";

            // New path from custom PathGenerator
            $newDirectory = $pathGenerator->getPath($item);

            // Generate new slug-based filename
            $model = $item->model;
            $baseName = MediaFileNamer::resolveBaseNameFromModel($model);
            $extension = pathinfo($item->file_name, PATHINFO_EXTENSION);
            $suffix = substr($item->uuid, 0, 8);
            $newFileName = "{$baseName}-{$suffix}.{$extension}";
            $newPath = $newDirectory.$newFileName;

            $exists = $storage->exists($currentPath);
            $status = $exists ? 'ready' : 'MISSING';

            $rows[] = [
                $item->id,
                $item->model_type,
                $item->collection_name,
                $currentPath,
                $newPath,
                $status,
            ];

            if ($exists) {
                $migrationPlan[] = [
                    'media' => $item,
                    'disk' => $disk,
                    'currentPath' => $currentPath,
                    'newPath' => $newPath,
                    'newFileName' => $newFileName,
                    'newDirectory' => $newDirectory,
                ];
            }
        }

        $this->table(
            ['ID', 'Model', 'Collection', 'Current Path', 'New Path', 'Status'],
            $rows
        );

        if ($dryRun || $migrationPlan === []) {
            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Proceed with migration?')) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar(count($migrationPlan));
        $bar->start();

        $succeeded = 0;
        $failed = 0;

        foreach ($migrationPlan as $plan) {
            try {
                $storage = Storage::disk($plan['disk']);

                // Move the file
                $storage->move($plan['currentPath'], $plan['newPath']);

                // Update the media record
                $plan['media']->update([
                    'file_name' => $plan['newFileName'],
                ]);

                // Clean up old empty directory
                $oldDir = dirname($plan['currentPath']);
                $remaining = $storage->files($oldDir);
                if (empty($remaining)) {
                    $storage->deleteDirectory($oldDir);
                }

                $succeeded++;
            } catch (\Throwable $e) {
                $this->newLine();
                $this->error("Failed to migrate media {$plan['media']->id}: {$e->getMessage()}");
                $failed++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Migration complete: {$succeeded} succeeded, {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
