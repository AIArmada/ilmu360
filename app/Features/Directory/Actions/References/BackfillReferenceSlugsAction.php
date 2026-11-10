<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\References;

use App\Features\Directory\Support\DirectoryModel;
use Illuminate\Database\Eloquent\Model;

/**
 * Regenerate stored reference slugs using the canonical title format.
 */
final readonly class BackfillReferenceSlugsAction
{
    public function __construct(
        private GenerateReferenceSlugAction $generateReferenceSlug,
    ) {}

    /**
     * @return list<string>
     */
    public function handle(): array
    {
        $changed = [];
        $referenceClass = DirectoryModel::reference();

        DirectoryModel::referenceQuery()
            ->orderBy('title')
            ->orderBy('id')
            ->chunk(100, function ($references) use (&$changed, $referenceClass): void {
                foreach ($references as $reference) {
                    if (! $reference instanceof Model) {
                        continue;
                    }

                    $slug = $this->generateReferenceSlug->forReference($reference);

                    if ($reference->getAttribute('slug') === $slug) {
                        continue;
                    }

                    $referenceClass::withoutTimestamps(function () use ($reference, $slug): void {
                        $reference->forceFill([
                            'slug' => $slug,
                        ])->saveQuietly();
                    });

                    $changed[] = (string) $reference->getKey();
                }
            });

        return $changed;
    }
}
