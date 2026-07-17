<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

abstract class AbstractIndexToScout extends Command
{
    /**
     * @return class-string
     */
    abstract protected function searchableModel(): string;

    abstract protected function searchableLabel(): string;

    public function handle(): int
    {
        $driver = (string) config('scout.driver');

        if (! in_array($driver, ['typesense', 'database'], true)) {
            $this->error('Scout driver must be set to Typesense or database. Current driver: '.$driver);
            $this->info('Set SCOUT_DRIVER=typesense or database in your .env file.');

            return self::FAILURE;
        }

        $label = $this->searchableLabel();
        $this->info("Starting {$label} Scout import using the {$driver} driver...");

        $status = $this->call('scout:import', [
            'model' => $this->searchableModel(),
            '--fresh' => (bool) $this->option('fresh'),
            '--chunk' => (string) $this->option('chunk'),
        ]);

        if ($status !== self::SUCCESS) {
            return $status;
        }

        $this->info(ucfirst($label).' Scout import completed.');

        return self::SUCCESS;
    }
}
