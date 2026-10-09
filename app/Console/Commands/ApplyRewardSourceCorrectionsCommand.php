<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Rewards\ApplyRewardSourceCorrectionsAction;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class ApplyRewardSourceCorrectionsCommand extends Command
{
    protected $signature = 'rewards:apply-source-corrections {--actor= : Staff/admin/system user id (system jobs may omit)} {--actor-role=system : Actor role (staff, admin, system)} {--as-of= : Decision timestamp (UTC, defaults to now)} {--limit=200 : Maximum correction keys to sweep}';

    protected $description = 'Reverse awards whose source outcomes were corrected or confirmed fraudulent.';

    public function handle(ApplyRewardSourceCorrectionsAction $sweep): int
    {
        $role = (string) $this->option('actor-role');
        $actor = $this->option('actor');

        if ($role === 'system') {
            $actorType = 'system';
            $actorId = is_string($actor) && $actor !== '' ? $actor : null;
        } else {
            if (! is_string($actor) || $actor === '') {
                $this->error('Staff/admin sweeps require --actor=<user id> for attribution.');

                return self::INVALID;
            }

            $actorType = 'user';
            $actorId = $actor;
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
            $report = $sweep->handle($actorType, $actorId, $role, $decided, (int) $this->option('limit'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        $reversed = count($report['reversed']);
        $rejected = count($report['rejected']);
        $recoveries = count($report['recoveries']);
        $contributions = $report['contributions'];
        $skipped = count($report['skipped']);
        $errors = count($report['errors']);

        $this->info("Corrections applied: {$reversed} reversed, {$rejected} rejected, {$recoveries} recoveries opened, {$contributions} contributions invalidated, {$skipped} already settled.");

        foreach ($report['errors'] as $awardId => $message) {
            $this->error("Award {$awardId}: {$message}");
        }

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
