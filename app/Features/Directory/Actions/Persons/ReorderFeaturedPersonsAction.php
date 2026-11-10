<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Persons;

use AIArmada\Persons\Actions\ReorderFeaturedPersonsAction as PackageReorder;
use App\Features\Directory\Support\DirectoryModel;
use App\Shared\Auditing\RecordsCustomAuditEvents;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Reorder curated featured people with the existing admin-only guard.
 */
final readonly class ReorderFeaturedPersonsAction
{
    /**
     * @param  list<string>  $order
     */
    public function handle(array $order): void
    {
        abort_unless(auth()->user()?->hasGlobalAdminAccess(), 403);

        DB::transaction(function () use ($order): void {
            app(PackageReorder::class)->handle(DirectoryModel::person(), $order, function (array $before, array $after): void {
                $person = DirectoryModel::query('person')->findOrFail($after[0]);

                if ($person instanceof Model && $person instanceof RecordsCustomAuditEvents) {
                    $person->recordCustomAudit(
                        'featured_reordered',
                        ['featured_person_ids' => $before],
                        ['featured_person_ids' => $after],
                    );
                }
            });
        });
    }
}
