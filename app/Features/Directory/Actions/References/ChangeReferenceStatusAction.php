<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\References;

use App\Features\Directory\Support\DirectoryModel;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Apply an explicit reference lifecycle transition.
 */
final readonly class ChangeReferenceStatusAction
{
    public function handle(string $referenceId, string $status): void
    {
        $reference = DirectoryModel::referenceQuery()->find($referenceId);

        if (! $reference instanceof Model) {
            throw new RuntimeException("Reference [{$referenceId}] was not found.");
        }

        $now = now();
        $attributes = [
            'status' => $status,
            'last_state_change_at' => $now,
        ];

        if ($status === 'verified') {
            $attributes['verified_at'] = $now;
        }

        if ($status === 'rejected') {
            $attributes['rejected_at'] = $now;
        }

        $reference->forceFill($attributes)->save();
    }
}
