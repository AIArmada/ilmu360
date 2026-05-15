<?php

declare(strict_types=1);

namespace App\Actions\Contributions;

use App\Forms\SharedFormSchema;
use Lorisleiva\Actions\Concerns\AsAction;

class ResolveContributionSubmissionStateAction
{
    use AsAction;

    /**
     * @param  array<string, mixed>  $state
     * @return array{state: array<string, mixed>, proposer_note: string|null}
     */
    public function handle(array $state): array
    {
        $note = isset($state['proposer_note']) && is_string($state['proposer_note'])
            ? trim($state['proposer_note'])
            : null;

        unset($state['proposer_note']);

        if (is_array($state['contacts'] ?? null)) {
            $state['contacts'] = SharedFormSchema::normalizeContactRowsForComparison($state['contacts']);
        }

        return [
            'state' => $state,
            'proposer_note' => $note !== '' ? $note : null,
        ];
    }
}
