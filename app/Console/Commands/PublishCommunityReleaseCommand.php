<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Share\PublishCommunityReleaseAction;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class PublishCommunityReleaseCommand extends Command
{
    protected $signature = 'rewards:publish-community-release {month : Fixed calendar month (YYYY-MM)} {--actor= : Publishing staff/admin user id} {--as-of= : Decision timestamp (UTC, defaults to now)}';

    protected $description = 'Publish the immutable delayed community release for one fixed month.';

    public function handle(PublishCommunityReleaseAction $publish): int
    {
        $actor = $this->option('actor');

        if (! is_string($actor) || $actor === '') {
            $this->error('Publishing requires --actor=<staff/admin user id> for attribution.');

            return self::INVALID;
        }

        $asOf = $this->option('as-of');
        $decided = null;

        if (is_string($asOf) && $asOf !== '') {
            try {
                $decided = CarbonImmutable::parse($asOf)->setTimezone('UTC');
            } catch (\Throwable) {
                $this->error('Decision timestamp (--as-of) must be parseable.');

                return self::INVALID;
            }
        }

        try {
            $release = $publish->handle(
                (string) $this->argument('month'),
                'user',
                $actor,
                $decided,
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return str_starts_with($exception->getMessage(), 'Insufficient data')
                ? self::FAILURE
                : self::INVALID;
        }

        $prefix = $release->wasRecentlyCreated ? 'Published' : 'Already published';

        $this->info("{$prefix} community release for {$release->period_start}..{$release->period_end} (sharers {$release->sharer_count}, participants {$release->participant_count}).");

        return self::SUCCESS;
    }
}
