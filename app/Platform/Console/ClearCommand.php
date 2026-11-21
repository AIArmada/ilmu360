<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Platform\FeatureCatalog;
use Illuminate\Console\Command;

#[\Illuminate\Console\Attributes\Description('Remove the cached Platform catalog')]
#[\Illuminate\Console\Attributes\Signature('platform:clear')]
final class ClearCommand extends Command
{
    public function handle(): int
    {
        $target = FeatureCatalog::cachePath($this->laravel->basePath());
        if (is_file($target)) {
            unlink($target);
        }
        FeatureCatalog::flushMemory();
        $this->components->info('Platform catalog cache cleared.');

        return self::SUCCESS;
    }
}
