<?php

declare(strict_types=1);

return [
    'features' => [
        'auto_capture' => (bool) env('COMMUNICATIONS_AUTO_CAPTURE', true),
        // Package is the only dispatch path (ADR Phase 9). Flag kept for emergency off-switch only.
        'dispatch_through_package' => (bool) env('COMMS_DISPATCH_THROUGH_PACKAGE', true),
    ],

    'fencing' => [
        'attempt_metadata_key' => env('COMMUNICATIONS_FENCING_METADATA_KEY', 'digest_mail_fence'),
    ],

    'redaction' => [
        'sensitive_substrings' => [
            'credential',
            'guest_claim',
            'claim_capability',
            'claim-capability',
            'verifier_hash',
        ],
        'exact_keys' => [
            'x-guest-credential',
            'guest_credential',
            'i360_guest',
            'x-claim-capability',
            'guest_claim_capability',
            'i360_guest_claim',
        ],
    ],
];
