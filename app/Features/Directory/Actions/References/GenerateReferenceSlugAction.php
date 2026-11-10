<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\References;

use AIArmada\CommerceSupport\Support\CanonicalSlug;
use AIArmada\CommerceSupport\Support\StableModelOrder;
use AIArmada\CommerceSupport\Support\UniqueSlug;
use App\Features\Directory\Support\DirectoryModel;
use App\Platform\Routing\SyncSlugRedirectAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Directory-owned reference slug generation and redirect synchronization.
 */
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

        $references = DirectoryModel::referenceQuery()
            ->where('references.title', $normalizedTitle)
            ->get();

        return StableModelOrder::sync($references, fn (Model $reference): bool => $this->syncReferenceSlug($reference));
    }

    public function syncReferenceSlug(Model $reference): bool
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
            DirectoryModel::reference(),
            $titleSlug,
            [],
            '',
            $ignoreReferenceId,
        );
    }

    public function forReference(Model $reference): string
    {
        return $this->handle(
            (string) $reference->getAttribute('display_title'),
            (string) $reference->getKey(),
        );
    }
}
