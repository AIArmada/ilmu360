<?php

namespace App\Actions\References;

use App\Actions\Slugs\Concerns\BuildsUniqueSlug;
use App\Actions\Slugs\Concerns\InteractsWithOrderedSlugModels;
use App\Actions\Slugs\SyncCanonicalSlugAction;
use App\Models\Reference;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

class GenerateReferenceSlugAction
{
    use AsAction;
    use BuildsUniqueSlug;
    use InteractsWithOrderedSlugModels;

    public function __construct(
        private readonly SyncCanonicalSlugAction $syncCanonicalSlugAction,
    ) {}

    public function syncReferenceSlugsForTitle(string $title): bool
    {
        $normalizedTitle = trim($title);

        if ($normalizedTitle === '') {
            return false;
        }

        $references = Reference::query()
            ->where('references.title', $normalizedTitle)
            ->get();

        return $this->syncOrderedModels($references, fn (Reference $reference): bool => $this->syncReferenceSlug($reference));
    }

    public function syncReferenceSlug(Reference $reference): bool
    {
        $slug = $this->forReference($reference);

        return $this->syncCanonicalSlugAction->persist($reference, $slug);
    }

    public function handle(?string $title, ?string $ignoreReferenceId = null): string
    {
        $normalizedTitle = trim((string) $title);
        $titleSlug = Str::slug($normalizedTitle);

        if ($titleSlug === '') {
            $titleSlug = 'rujukan';
        }

        return $this->buildUniqueSlug(
            Reference::class,
            $titleSlug,
            [],
            '',
            $ignoreReferenceId,
        );
    }

    public function forReference(Reference $reference): string
    {
        return $this->handle($reference->title, (string) $reference->getKey());
    }
}
