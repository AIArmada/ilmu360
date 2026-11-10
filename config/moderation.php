<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Review identity lock wait
    |--------------------------------------------------------------------------
    |
    | Seconds a contribution submit or approval waits for the per-Maps-identity
    | lock before giving up. Keeps concurrent submissions and approvals for
    | the same place serialized instead of creating duplicates.
    |
    */
    'review_lock_block_seconds' => (int) env('MODERATION_REVIEW_LOCK_BLOCK_SECONDS', 10),
];
