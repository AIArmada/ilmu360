<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Platform\FeatureCatalog;
use Illuminate\Console\Command;
use RuntimeException;

#[\Illuminate\Console\Attributes\Description('Validate and cache feature ownership and extension manifests')]
#[\Illuminate\Console\Attributes\Signature('platform:cache')]
final class CacheCommand extends Command
{
    public function handle(): int
    {
        $root = $this->laravel->basePath();
        $catalog = FeatureCatalog::scan($root);
        $target = FeatureCatalog::cachePath($root);
        $temp = $target.'.'.getmypid().'.tmp';
        $payload = "<?php\n\ndeclare(strict_types=1);\n\nreturn ".var_export($catalog, true).";\n";

        if (file_put_contents($temp, $payload, LOCK_EX) === false || ! rename($temp, $target)) {
            @unlink($temp);
            throw new RuntimeException("Unable to write {$target}");
        }

        FeatureCatalog::flushMemory();
        $this->components->info(sprintf(
            'Cached %d features, %d resources, %d extension slots.',
            count($catalog['features']), count($catalog['owners']), count($catalog['extensions']),
        ));

        return self::SUCCESS;
    }
}
