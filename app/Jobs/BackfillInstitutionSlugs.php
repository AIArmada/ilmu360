<?php

namespace App\Jobs;

use App\Actions\Institutions\GenerateInstitutionSlugAction;
use App\Models\Institution;
use App\Support\Cache\PublicListingsCache;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class BackfillInstitutionSlugs implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function handle(
        GenerateInstitutionSlugAction $generateInstitutionSlugAction,
        PublicListingsCache $publicListingsCache,
    ): void {
        Institution::query()
            ->with([
                'address.country',
                'address.state',
                'address.district',
                'address.subdistrict',
            ])
            ->orderBy('name')
            ->orderBy('id')
            ->chunk(100, function ($institutions) use ($generateInstitutionSlugAction): void {
                foreach ($institutions as $institution) {
                    $slug = $generateInstitutionSlugAction->forInstitution($institution);

                    if ($institution->slug === $slug) {
                        continue;
                    }

                    Institution::withoutTimestamps(function () use ($institution, $slug): void {
                        $institution->forceFill([
                            'slug' => $slug,
                        ])->saveQuietly();
                    });
                }
            });

        $publicListingsCache->bustMajlisListing();
    }

    public function uniqueId(): string
    {
        return 'institution-slug-backfill';
    }
}
