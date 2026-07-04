<?php

declare(strict_types=1);

$tablePrefix = '';

return [
    'database' => [
        'table_prefix' => $tablePrefix,
        'json_column_type' => env('REFERENCES_JSON_COLUMN_TYPE', 'jsonb'),
        'tables' => [
            'references' => env('REFERENCES_TABLE_REFERENCES', $tablePrefix.'references'),
        ],
    ],
    'slug' => [
        'source' => env('REFERENCES_SLUG_SOURCE', 'title'),
        'max_length' => (int) env('REFERENCES_SLUG_MAX_LENGTH', 200),
    ],
];
