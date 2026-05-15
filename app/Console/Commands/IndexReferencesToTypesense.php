<?php

namespace App\Console\Commands;

use App\Models\Reference;
use Illuminate\Console\Command;

class IndexReferencesToTypesense extends Command
{
    /**
     * @var string
     */
    protected $signature = 'search:index-references
                            {--fresh : Flush the current Scout index before importing}
                            {--chunk=500 : Number of records to process per chunk}';

    /**
     * @var string
     */
    protected $description = 'Import all searchable references into the configured Scout driver';

    public function handle(): int
    {
        $driver = (string) config('scout.driver');

        if (! in_array($driver, ['typesense', 'database'], true)) {
            $this->error('Scout driver must be set to Typesense or database. Current driver: '.$driver);
            $this->info('Set SCOUT_DRIVER=typesense or SCOUT_DRIVER=database in your .env file.');

            return self::FAILURE;
        }

        $this->info("Starting reference Scout import using the {$driver} driver...");

        $status = $this->call('scout:import', [
            'model' => Reference::class,
            '--fresh' => (bool) $this->option('fresh'),
            '--chunk' => (string) $this->option('chunk'),
        ]);

        if ($status !== self::SUCCESS) {
            return $status;
        }

        $this->info('Reference Scout import completed.');

        return self::SUCCESS;
    }
}
