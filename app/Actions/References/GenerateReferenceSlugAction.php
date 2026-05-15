<?php

namespace App\Actions\References;

use App\Actions\Slugs\Concerns\InteractsWithOrderedSlugModels;
use App\Actions\Slugs\SyncCanonicalSlugAction;
use App\Models\Reference;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

class GenerateReferenceSlugAction
{
    use AsAction;
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

        $sequence = $this->nextSequenceForExactTitle($normalizedTitle, $ignoreReferenceId);

        do {
            $candidate = $titleSlug;

            if ($sequence > 1) {
                $candidate .= '-'.$sequence;
            }

            $sequence++;
        } while ($this->slugExists($candidate, $ignoreReferenceId));

        return $candidate;
    }

    public function forReference(Reference $reference): string
    {
        return $this->handle($reference->title, (string) $reference->getKey());
    }

    private function nextSequenceForExactTitle(string $title, ?string $ignoreReferenceId): int
    {
        $matchingReferences = Reference::query()
            ->where('references.title', $title)
            ->get();

        if ($ignoreReferenceId !== null && $ignoreReferenceId !== '') {
            $existingSequence = $this->existingModelSequence($matchingReferences, $ignoreReferenceId);

            if ($existingSequence !== null) {
                return $existingSequence;
            }

            $matchingReferences = $matchingReferences
                ->reject(fn (Reference $reference): bool => (string) $reference->getKey() === $ignoreReferenceId)
                ->values();
        }

        $matchingCount = $matchingReferences->count();

        return $matchingCount > 0 ? $matchingCount + 1 : 1;
    }

    private function slugExists(string $slug, ?string $ignoreReferenceId): bool
    {
        return Reference::query()
            ->where('slug', $slug)
            ->when(
                $ignoreReferenceId !== null && $ignoreReferenceId !== '',
                fn ($query) => $query->where('references.id', '!=', $ignoreReferenceId),
            )
            ->exists();
    }
}
