<?php

declare(strict_types=1);

namespace App\Features\Registration\Queries;

use App\Features\Registration\Api\PaidRegistrationFacts as PaidRegistrationFactsContract;
use App\Platform\Models\MorphModelResolver;
use Illuminate\Support\Str;

final readonly class DatabasePaidRegistrationFacts implements PaidRegistrationFactsContract
{
    /**
     * @param  list<string>  $registrationIds
     * @return list<string>
     */
    public function paidRegistrationIds(array $registrationIds): array
    {
        $ids = array_values(array_unique(array_filter(
            $registrationIds,
            Str::isUuid(...),
        )));
        $paid = [];
        $registration = MorphModelResolver::resolve('registration');

        foreach (array_chunk($ids, 500) as $chunk) {
            $paid = [
                ...$paid,
                ...$registration::query()
                    ->withoutGlobalScopes()
                    ->whereKey($chunk)
                    ->where('total_amount', '>', 0)
                    ->where('payment_status', 'paid')
                    ->pluck('id')
                    ->map(static fn (mixed $id): string => (string) $id)
                    ->all(),
            ];
        }

        return array_values(array_unique($paid));
    }
}
