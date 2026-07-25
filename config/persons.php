<?php

declare(strict_types=1);
use AIArmada\Addressing\Models\AddressCountry;
use App\Models\Person;

$tablePrefix = '';

return [

    'database' => [
        'table_prefix' => $tablePrefix,
        'tables' => [
            'persons' => env('PERSONS_TABLE_PERSONS', $tablePrefix.'persons'),
            'person_names' => env('PERSONS_TABLE_PERSON_NAMES', $tablePrefix.'person_names'),
            'title_categories' => env('PERSONS_TABLE_TITLE_CATEGORIES', $tablePrefix.'title_categories'),
            'titles' => env('PERSONS_TABLE_TITLES', $tablePrefix.'titles'),
            'title_issuers' => env('PERSONS_TABLE_TITLE_ISSUERS', $tablePrefix.'title_issuers'),
            'title_assignments' => env('PERSONS_TABLE_TITLE_ASSIGNMENTS', $tablePrefix.'title_assignments'),
            'credential_definitions' => env('PERSONS_TABLE_CREDENTIAL_DEFINITIONS', $tablePrefix.'credential_definitions'),
            'credential_assignments' => env('PERSONS_TABLE_CREDENTIAL_ASSIGNMENTS', $tablePrefix.'credential_assignments'),
            'affiliations' => env('PERSONS_TABLE_AFFILIATIONS', $tablePrefix.'affiliations'),
            'affiliation_roles' => env('PERSONS_TABLE_AFFILIATION_ROLES', $tablePrefix.'affiliation_roles'),
        ],
    ],

    // Host application may subclass package models. Resolved through ModelResolver.
    'models' => [
        'person' => Person::class,
        'country' => AddressCountry::class,
        'institution' => null,
    ],

    // Optional integration toggles.
    'integrations' => [
        'addressing' => [
            'enabled' => (bool) env('PERSONS_ADDRESSING_ENABLED', false),
        ],
    ],

];
