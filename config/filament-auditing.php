<?php

declare(strict_types=1);

use App\Filament\Resources\Audits\AuditResource;
use App\Models\Event;
use App\Models\Institution;
use App\Models\User;
use App\Models\Venue;
use Filament\Tables\Columns\TextColumn;

return [

    'audits_sort' => [
        'column' => 'created_at',
        'direction' => 'desc',
    ],

    'is_lazy' => true,

    'grouped_table_actions' => false,

    'audits_extend' => [
        'tags' => [
            'class' => TextColumn::class,
            'methods' => [
                'label' => 'Context',
                'toggleable' => true,
                'placeholder' => 'App',
            ],
        ],
    ],

    'custom_audits_view' => false,

    'custom_view_parameters' => [
    ],

    'mapping' => [
        'user_id' => [
            'model' => User::class,
            'field' => 'name',
            'label' => 'User',
        ],
        'submitter_id' => [
            'model' => User::class,
            'field' => 'name',
            'label' => 'Submitter',
        ],
        'reviewer_id' => [
            'model' => User::class,
            'field' => 'name',
            'label' => 'Reviewer',
        ],
        'reporter_id' => [
            'model' => User::class,
            'field' => 'name',
            'label' => 'Reporter',
        ],
        'handled_by' => [
            'model' => User::class,
            'field' => 'name',
            'label' => 'Handled By',
        ],
        'claimant_id' => [
            'model' => User::class,
            'field' => 'name',
            'label' => 'Claimant',
        ],
        'institution_id' => [
            'model' => Institution::class,
            'field' => 'name',
            'label' => 'Institution',
        ],
        'venue_id' => [
            'model' => Venue::class,
            'field' => 'name',
            'label' => 'Venue',
        ],
        'event_id' => [
            'model' => Event::class,
            'field' => 'title',
            'label' => 'Event',
        ],
        'daily_prayer_institution_id' => [
            'model' => Institution::class,
            'field' => 'name',
            'label' => 'Daily Prayer Institution',
        ],
        'friday_prayer_institution_id' => [
            'model' => Institution::class,
            'field' => 'name',
            'label' => 'Friday Prayer Institution',
        ],
    ],

    'resources' => [
        'AuditResource' => AuditResource::class,
    ],

    'tenancy' => [
        'enabled' => false,
        'model' => null,
        'relationship_name' => null,
        'column' => null,
    ],

];
