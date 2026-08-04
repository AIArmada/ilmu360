<?php

declare(strict_types=1);

use App\Filament\Resources\Events\EventAdminContextFormExtension;
use App\Filament\Resources\Events\EventMediaFormExtension;
use App\Filament\Resources\Events\RelationManagers\ReferencesRelationManager;
use App\Filament\Resources\Events\RelationManagers\SeatMapsRelationManager;
use App\Filament\Resources\Events\RelationManagers\TicketTypesRelationManager;

return [
    'navigation' => [
        'group' => 'Events',
    ],
    'resources' => [
        'enabled' => [
            'event' => true,
            'occurrence' => true,
            'session' => true,
            'venue' => false,
            'venue_space' => false,
            'registration' => true,
            'registration_participant' => true,
            'attendance' => true,
            'change_log' => true,
            'event_template' => true,
        ],
        'event_form_extensions' => [
            EventAdminContextFormExtension::class,
            EventMediaFormExtension::class,
        ],
        'event_relation_managers' => [
            ReferencesRelationManager::class,
            TicketTypesRelationManager::class,
            SeatMapsRelationManager::class,
        ],
        'occurrence_relation_managers' => [
            TicketTypesRelationManager::class,
            SeatMapsRelationManager::class,
        ],
        'session_relation_managers' => [
            TicketTypesRelationManager::class,
            SeatMapsRelationManager::class,
        ],
    ],
];
