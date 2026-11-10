<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\References;

use Illuminate\Database\Eloquent\Model;

/**
 * Create a reference from a contribution submission.
 */
final readonly class CreateReferenceAction
{
    public function __construct(
        private SaveReferenceAction $saveReference,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload, string $status = 'pending'): Model
    {
        return $this->saveReference->handle([
            ...$payload,
            'status' => $status,
        ]);
    }
}
