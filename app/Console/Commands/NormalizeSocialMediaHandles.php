<?php

namespace App\Console\Commands;

use App\Models\SocialMedia;
use App\Support\SocialMedia\SocialMediaLinkResolver;
use Illuminate\Console\Command;

class NormalizeSocialMediaHandles extends Command
{
    protected $signature = 'social-media:normalize
                            {--dry-run : Preview normalization changes}
                            {--force : Run without confirmation}';

    protected $description = 'Normalize social media rows into username-first format with canonical URL resolution';

    public function handle(): int
    {
        $records = SocialMedia::query()->get();
        $dryRun = (bool) $this->option('dry-run');

        if ($records->isEmpty()) {
            $this->info('No social media records found.');

            return self::SUCCESS;
        }

        $changes = [];

        foreach ($records as $record) {
            $beforePlatform = is_string($record->platform) ? $record->platform : null;
            $beforeUsername = is_string($record->username) ? $record->username : null;
            $beforeUrl = is_string($record->getRawOriginal('url')) ? $record->getRawOriginal('url') : null;

            $normalized = SocialMediaLinkResolver::normalize(
                $beforePlatform,
                $beforeUsername,
                $beforeUrl,
            );

            if (
                $beforePlatform !== $normalized['platform']
                || $beforeUsername !== $normalized['username']
                || $beforeUrl !== $normalized['url']
            ) {
                $changes[] = [
                    'record' => $record,
                    'before' => [
                        'platform' => $beforePlatform,
                        'username' => $beforeUsername,
                        'url' => $beforeUrl,
                    ],
                    'after' => $normalized,
                ];
            }
        }

        if ($changes === []) {
            $this->info('All social media rows are already normalized.');

            return self::SUCCESS;
        }

        $this->info('Detected '.count($changes).' record(s) to normalize.');

        if ($dryRun) {
            foreach (array_slice($changes, 0, 20) as $change) {
                /** @var SocialMedia $record */
                $record = $change['record'];

                $this->line(sprintf(
                    '- %s (%s): %s -> %s',
                    $record->id,
                    $record->socialable_type,
                    json_encode($change['before'], JSON_THROW_ON_ERROR),
                    json_encode($change['after'], JSON_THROW_ON_ERROR),
                ));
            }

            if (count($changes) > 20) {
                $this->line('... output truncated. Re-run without --dry-run to apply all changes.');
            }

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Apply normalization changes now?')) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar(count($changes));
        $bar->start();

        foreach ($changes as $change) {
            /** @var SocialMedia $record */
            $record = $change['record'];
            /** @var array{platform: string, username: string|null, url: string|null} $after */
            $after = $change['after'];

            $record->forceFill([
                'platform' => $after['platform'],
                'username' => $after['username'],
                'url' => $after['url'],
            ])->saveQuietly();

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info('Normalization completed successfully.');

        return self::SUCCESS;
    }
}
