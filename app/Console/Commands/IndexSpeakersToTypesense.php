<?php

namespace App\Console\Commands;

use App\Models\Speaker;
use Illuminate\Console\Command;

class IndexSpeakersToTypesense extends Command
{
    /**
     * @var string
     */
    protected $signature = 'search:index-speakers
                            {--fresh : Flush the current Scout index before importing}
                            {--chunk=500 : Number of records to process per chunk}';

    /**
     * @var string
     */
    protected $description = 'Import all searchable speakers into the configured Scout driver';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $driver = (string) config('scout.driver');

        if (! in_array($driver, ['typesense', 'database'], true)) {
            $this->error('Scout driver must be set to Typesense or database. Current driver: '.$driver);
            $this->info('Set SCOUT_DRIVER=typesense or SCOUT_DRIVER=database in your .env file.');

            return self::FAILURE;
        }

        $this->info("Starting speaker Scout import using the {$driver} driver...");

        $status = $this->call('scout:import', [
            'model' => Speaker::class,
            '--fresh' => (bool) $this->option('fresh'),
            '--chunk' => (string) $this->option('chunk'),
        ]);

        if ($status !== self::SUCCESS) {
            return $status;
        }

        $this->info('Speaker Scout import completed.');

        return self::SUCCESS;
    }
}
