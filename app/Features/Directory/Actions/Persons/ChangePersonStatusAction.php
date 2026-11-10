<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Persons;

use App\Features\Directory\Support\DirectoryModel;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Apply an explicit person status transition for review workflows.
 */
final readonly class ChangePersonStatusAction
{
    private const array STATUSES = ['pending', 'verified', 'rejected', 'inactive'];

    public function handle(string $personId, string $status): void
    {
        if (! in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException("Unknown person status [{$status}].");
        }

        $person = DirectoryModel::query('person')->find($personId);

        if (! $person instanceof Model) {
            throw new InvalidArgumentException("Person [{$personId}] was not found.");
        }

        $now = now();

        $person->forceFill([
            'status' => $status,
            'verified_at' => $status === 'verified' ? $now : $person->getAttribute('verified_at'),
            'rejected_at' => $status === 'rejected' ? $now : $person->getAttribute('rejected_at'),
            'last_state_change_at' => $now,
        ])->save();
    }
}
