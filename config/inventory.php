<?php

declare(strict_types=1);

use AIArmada\Ticketing\Models\TicketType;

return [
    /*
    |--------------------------------------------------------------------------
    | Integration Models
    |--------------------------------------------------------------------------
    |
    | Event tickets carry their own inventory levels and are inventoried like
    | product variants during checkout reservation. Registering the ticket
    | type here allow-lists it for inventory resolution.
    |
    */
    'models' => [
        'variant' => TicketType::class,
    ],
];
