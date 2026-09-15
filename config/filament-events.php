<?php

declare(strict_types=1);

use AIArmada\FilamentEvents\Extensions\DefaultEventMediaExtension;
use AIArmada\FilamentSeating\RelationManagers\SeatMapsRelationManager;
use AIArmada\FilamentTicketing\RelationManagers\TicketTypesRelationManager;
use App\Filament\Resources\Events\EventAdminContextFormExtension;
use App\Filament\Resources\Events\RelationManagers\ReferencesRelationManager;

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
            DefaultEventMediaExtension::class,
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
