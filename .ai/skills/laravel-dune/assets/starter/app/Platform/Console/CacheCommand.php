<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Platform\FeatureCatalog;
use Illuminate\Console\Command;

final class CacheCommand extends Command
{
    protected $signature = 'platform:cache';

    protected $description = 'Validate and cache platform feature manifests and extension slots';

    public function handle(): int
    {
        $root = $this->laravel->basePath();
        $catalog = FeatureCatalog::scan($root);
        $target = FeatureCatalog::cachePath($root);
        $temp = $target.'.'.getmypid().'.tmp';

        $payload = "<?php\n\nreturn ".var_export($catalog, true).";\n";
        file_put_contents($temp, $payload, LOCK_EX);
        rename($temp, $target);
        FeatureCatalog::flushMemory();

        $this->components->info(sprintf(
            'Cached %d feature(s), %d owned resource(s), and %d extension slot(s).',
            count($catalog['features']),
            count($catalog['owners']),
            count($catalog['extensions']),
        ));

        return self::SUCCESS;
    }
}
