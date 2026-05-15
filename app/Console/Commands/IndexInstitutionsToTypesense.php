<?php

namespace App\Console\Commands;

use App\Models\Institution;
use Illuminate\Console\Command;

class IndexInstitutionsToTypesense extends Command
{
    /**
     * @var string
     */
    protected $signature = 'search:index-institutions
                            {--fresh : Flush the current Scout index before importing}
                            {--chunk=500 : Number of records to process per chunk}';

    /**
     * @var string
     */
    protected $description = 'Import all searchable institutions into the configured Scout driver';

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

        $this->info("Starting institution Scout import using the {$driver} driver...");

        $status = $this->call('scout:import', [
            'model' => Institution::class,
            '--fresh' => (bool) $this->option('fresh'),
            '--chunk' => (string) $this->option('chunk'),
        ]);

        if ($status !== self::SUCCESS) {
            return $status;
        }

        $this->info('Institution Scout import completed.');

        return self::SUCCESS;
    }
}
