<?php

declare(strict_types=1);

return [
    'features' => [
        'auto_capture' => (bool) env('COMMUNICATIONS_AUTO_CAPTURE', true),
        // Package is the only dispatch path (ADR Phase 9). Flag kept for emergency off-switch only.
        'dispatch_through_package' => (bool) env('COMMS_DISPATCH_THROUGH_PACKAGE', true),
    ],
];
