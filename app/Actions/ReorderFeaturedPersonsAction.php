<?php

namespace App\Actions;

use AIArmada\Persons\Actions\ReorderFeaturedPersonsAction as PackageReorder;
use App\Models\Person;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class ReorderFeaturedPersonsAction
{
    use AsAction;

    /** @param list<string> $order */
    public function handle(array $order): void
    {
        abort_unless(auth()->user()?->hasGlobalAdminAccess(), 403);

        DB::transaction(function () use ($order): void {
            app(PackageReorder::class)->handle(Person::class, $order, function (array $before, array $after): void {
                Person::query()->findOrFail($after[0])->recordCustomAudit(
                    'featured_reordered', ['featured_person_ids' => $before], ['featured_person_ids' => $after],
                );
            });
        });
    }
}
