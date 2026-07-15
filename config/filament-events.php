<?php

declare(strict_types=1);

return [
    'navigation' => [
        'group' => 'Events',
    ],
    'resources' => [
        'enabled' => [
            'event' => true,
            'occurrence' => true,
            'session' => true,
            'venue' => true,
            'registration' => true,
            'registration_participant' => true,
            'attendance' => true,
            'change_log' => true,
            'event_template' => true,
        ],
    ],
];
