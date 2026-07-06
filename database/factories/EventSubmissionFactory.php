<?php

declare(strict_types=1);

namespace Database\Factories;

use AIArmada\Events\Database\Factories\EventSubmissionFactory as PackageEventSubmissionFactory;
use App\Models\Event;
use App\Models\EventSubmission;
use App\Models\User;

/**
 * @extends PackageEventSubmissionFactory
 */
class EventSubmissionFactory extends PackageEventSubmissionFactory
{
    protected $model = EventSubmission::class;

    public function definition(): array
    {
        return array_merge(parent::definition(), [
            'event_id' => Event::factory(),
            'submitter_type' => User::class,
            'submitter_id' => User::factory(),
            'submission_data' => [
                'submitter_name' => fake()->name(),
            ],
        ]);
    }
}
