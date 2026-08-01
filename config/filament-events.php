<?php

declare(strict_types=1);
use App\Filament\Resources\Events\EventMediaFormExtension;

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
        'event_form_extensions' => [
            EventMediaFormExtension::class,
        ],
    ],
];
