<?php

declare(strict_types=1);

return array_replace_recursive(
    require base_path('vendor/aiarmada/filament-signals/config/filament-signals.php'),
    [
        'features' => [
            'dashboard' => true,
        ],
    ],
);
