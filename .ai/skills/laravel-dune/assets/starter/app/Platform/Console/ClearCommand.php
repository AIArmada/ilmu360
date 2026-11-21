<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Platform\FeatureCatalog;
use Illuminate\Console\Command;

final class ClearCommand extends Command
{
    protected $signature = 'platform:clear';

    protected $description = 'Remove the cached Platform feature catalog';

    public function handle(): int
    {
        $target = FeatureCatalog::cachePath($this->laravel->basePath());
        if (is_file($target)) {
            unlink($target);
        }
        FeatureCatalog::flushMemory();

        $this->components->info('Platform feature catalog cache cleared.');

        return self::SUCCESS;
    }
}
