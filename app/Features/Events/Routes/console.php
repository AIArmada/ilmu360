<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

Schedule::command('app:sync-public-submission-locks')
    ->hourly()
    ->timezone('Asia/Kuala_Lumpur')
    ->name('sync-public-submission-locks')
    ->withoutOverlapping();
