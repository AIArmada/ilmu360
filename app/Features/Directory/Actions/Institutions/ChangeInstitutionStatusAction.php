<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Institutions;

use App\Features\Directory\Support\DirectoryModel;
use App\Shared\Directory\Enums\InstitutionStatus;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Apply an explicit institution status transition for contribution review.
 */
final readonly class ChangeInstitutionStatusAction
{
    public function handle(string $institutionId, string $status): void
    {
        $resolved = InstitutionStatus::tryFrom($status);

        if (! $resolved instanceof InstitutionStatus) {
            throw new InvalidArgumentException("Unknown institution status [{$status}].");
        }

        $institution = DirectoryModel::query('institution')->find($institutionId);

        if (! $institution instanceof Model) {
            throw new InvalidArgumentException("Institution [{$institutionId}] was not found.");
        }

        $now = now();

        $institution->forceFill([
            'status' => $resolved,
            'verified_at' => $resolved === InstitutionStatus::Verified ? $now : $institution->getAttribute('verified_at'),
            'rejected_at' => $resolved === InstitutionStatus::Rejected ? $now : $institution->getAttribute('rejected_at'),
            'last_state_change_at' => $now,
        ])->save();
    }
}
