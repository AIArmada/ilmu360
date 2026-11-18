<?php

declare(strict_types=1);

namespace App\Features\References\Actions;

use AIArmada\CommerceSupport\Contracts\SlugRedirectRecorder;
use AIArmada\CommerceSupport\Support\CanonicalSlug;
use AIArmada\CommerceSupport\Support\StableModelOrder;
use AIArmada\CommerceSupport\Support\UniqueSlug;
use App\Features\References\Models\Reference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * owner-scoped reference slug generation and redirect synchronization.
 */
class GenerateReferenceSlugAction
{
    use AsAction;

    public function __construct(
        private readonly SlugRedirectRecorder $slugRedirectRecorder,
    ) {}

    public function syncReferenceSlugsForTitle(string $title): bool
    {
        $normalizedTitle = trim($title);

        if ($normalizedTitle === '') {
            return false;
        }

        $references = Reference::query()->withoutOwnerScope()
            ->where('references.title', $normalizedTitle)
            ->get();

        return StableModelOrder::sync($references, fn (Model $reference): bool => $this->syncReferenceSlug($reference));
    }

    public function syncReferenceSlug(Model $reference): bool
    {
        $slug = $this->forReference($reference);

        return CanonicalSlug::persist($reference, $slug, $this->slugRedirectRecorder);
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

    public function forReference(Model $reference): string
    {
        return $this->handle(
            (string) $reference->getAttribute('display_title'),
            (string) $reference->getKey(),
        );
    }
}
