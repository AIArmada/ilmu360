<?php

declare(strict_types=1);

namespace App\Features\Communications\Actions;

use AIArmada\Communications\Models\CommunicationPreference;
use Lorisleiva\Actions\Concerns\AsAction;

final class SyncNotificationPreferenceAction
{
    use AsAction;

    /**
     * @param  array<string, mixed>  $match
     * @param  array<string, mixed>  $values
     */
    public function handle(array $match, array $values): CommunicationPreference
    {
        $preference = $this->find($match);

        if (! $preference instanceof CommunicationPreference) {
            $preference = new CommunicationPreference;
            $preference->forceFill(array_merge($match, $values));
            $preference->save();

            return $preference;
        }

        $preference->update($values);

        return $preference;
    }

    /**
     * @param  array<string, mixed>  $match
     */
    private function find(array $match): ?CommunicationPreference
    {
        $query = CommunicationPreference::query();

        foreach ($match as $column => $value) {
            $value === null
                ? $query->whereNull($column)
                : $query->where($column, $value);
        }

        return $query->first();
    }
}
