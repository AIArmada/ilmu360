<?php

declare(strict_types=1);

return [
    'disk' => env('SHARE_CARDS_DISK', 'public'),

    'prune_after_days' => 30,

    'prewarm_on_save' => true,
];
