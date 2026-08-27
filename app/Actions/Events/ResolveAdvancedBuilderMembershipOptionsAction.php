<?php

declare(strict_types=1);

namespace App\Actions\Events;

use App\Models\Institution;
use App\Models\User;
use Lorisleiva\Actions\Concerns\AsAction;

class ResolveAdvancedBuilderMembershipOptionsAction
{
    use AsAction;

    /**
     * @return array{
     *     institution_options: array<string, string>,
     *     person_options: array<string, string>
     * }
     */
    public function handle(User $user): array
    {
        return [
            'institution_options' => $user->institutions()
                ->whereIn('status', ['verified', 'pending'])
                ->orderBy('name')
                ->with('names')
                ->get(['institutions.id', 'institutions.name'])
                ->mapWithKeys(fn (Institution $institution): array => [(string) $institution->id => $institution->display_name])
                ->all(),
            'person_options' => $user->persons()
                ->whereIn('status', ['verified', 'pending'])
                ->orderBy('name')
                ->pluck('persons.name', 'persons.id')
                ->all(),
        ];
    }
}
