<?php

namespace App\Console\Commands;

use App\Models\Event;
use Illuminate\Console\Command;

class IndexEventsToTypesense extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'search:index-events 
                            {--fresh : Drop existing index and recreate}
                            {--chunk=500 : Number of records to process per chunk}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import all searchable events into the configured Scout driver';

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

        $this->info("Starting event Scout import using the {$driver} driver...");

        $status = $this->call('scout:import', [
            'model' => Event::class,
            '--fresh' => (bool) $this->option('fresh'),
            '--chunk' => (string) $this->option('chunk'),
        ]);

        if ($status !== self::SUCCESS) {
            return $status;
        }

        $this->info('Event Scout import completed.');

        return self::SUCCESS;
    }
}
