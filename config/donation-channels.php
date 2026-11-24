<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Countries With Routing Codes
    |--------------------------------------------------------------------------
    |
    | Countries whose bank transfers carry an additional local clearing or
    | branch code (a routing / sort code) next to the account number, for
    | example an Indonesian clearing bank code or a Singapore branch code.
    | Countries absent from this list reject routing codes on their banks.
    |
    */

    'routing_code_countries' => ['ID', 'SG'],
];
