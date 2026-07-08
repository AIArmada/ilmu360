<?php

declare(strict_types=1);

return [
    'features' => [
        'auto_capture' => (bool) env('COMMUNICATIONS_AUTO_CAPTURE', true),
        'dispatch_through_package' => (bool) env('COMMS_DISPATCH_THROUGH_PACKAGE', false),
    ],
];
