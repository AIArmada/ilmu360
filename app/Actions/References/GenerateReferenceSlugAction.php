<?php

namespace App\Actions\References;

use AIArmada\CommerceSupport\Support\CanonicalSlug;
use AIArmada\CommerceSupport\Support\StableModelOrder;
use AIArmada\CommerceSupport\Support\UniqueSlug;
use App\Actions\Slugs\SyncSlugRedirectAction;
use App\Models\Reference;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

class GenerateReferenceSlugAction
{
    use AsAction;

    public function __construct(
        private readonly SyncSlugRedirectAction $syncSlugRedirectAction,
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

        return StableModelOrder::sync($references, fn (Reference $reference): bool => $this->syncReferenceSlug($reference));
    }

    public function syncReferenceSlug(Reference $reference): bool
    {
        $slug = $this->forReference($reference);

        return CanonicalSlug::persist($reference, $slug, $this->syncSlugRedirectAction);
    }

    public function handle(?string $title, ?string $ignoreReferenceId = null): string
    {
        $normalizedTitle = trim((string) $title);
        $titleSlug = Str::slug($normalizedTitle);

        if ($titleSlug === '') {
            $titleSlug = 'rujukan';
        }

        return UniqueSlug::build(
            Reference::class,
            $titleSlug,
            [],
            '',
            $ignoreReferenceId,
        );
    }

    public function forReference(Reference $reference): string
    {
        return $this->handle($reference->displayTitle(), (string) $reference->getKey());
    }
}
