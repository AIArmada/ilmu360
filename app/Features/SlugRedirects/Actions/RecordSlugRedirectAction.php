<?php

declare(strict_types=1);

namespace App\Features\SlugRedirects\Actions;

use AIArmada\Signals\Models\SignalEvent;
use AIArmada\Signals\Models\TrackedProperty;
use App\Features\SlugRedirects\Models\SlugRedirect;
use App\Platform\Routing\PublicSlugRegistry;
use App\Platform\Routing\PublicSlugResource;
use Carbon\CarbonImmutable;
use Lorisleiva\Actions\Concerns\AsAction;

final class RecordSlugRedirectAction
{
    use AsAction;

    public function __construct(
        private readonly PublicSlugRegistry $publicSlugRegistry,
    ) {}

    public function handle(string $redirectableType, string $redirectableId, string $currentSlug, ?string $previousSlug): bool
    {
        $resource = $this->publicSlugRegistry->resourceForType($redirectableType);
        $currentSlug = $this->normalizeSlug($currentSlug);

        if (! $resource instanceof PublicSlugResource || $currentSlug === null) {
            return false;
        }

        $destinationPath = $resource->pathForSlug($currentSlug);

        SlugRedirect::query()
            ->where('source_path', $destinationPath)
            ->delete();

        $didChange = SlugRedirect::query()
            ->where('redirectable_type', $redirectableType)
            ->where('redirectable_id', $redirectableId)
            ->update([
                'destination_slug' => $currentSlug,
                'destination_path' => $destinationPath,
                'updated_at' => now(),
            ]) > 0;

        $previousSlug = $this->normalizeSlug($previousSlug);

        if ($previousSlug === null || $previousSlug === $currentSlug) {
            return $didChange;
        }

        $sourcePath = $resource->pathForSlug($previousSlug);

        if ($sourcePath === $destinationPath) {
            return $didChange;
        }

        $firstVisitedAt = $this->firstVisitedAt($sourcePath);

        if ($resource->requiresVisitedSource && ! ($firstVisitedAt instanceof CarbonImmutable)) {
            return $didChange;
        }

        SlugRedirect::query()->updateOrCreate(
            ['source_path' => $sourcePath],
            [
                'redirectable_type' => $redirectableType,
                'redirectable_id' => $redirectableId,
                'source_slug' => $previousSlug,
                'destination_slug' => $currentSlug,
                'destination_path' => $destinationPath,
                'first_visited_at' => $firstVisitedAt,
            ],
        );

        return true;
    }

    private function firstVisitedAt(string $path): ?CarbonImmutable
    {
        $trackedProperty = TrackedProperty::query()
            ->withoutOwnerScope()
            ->whereNull('owner_type')
            ->whereNull('owner_id')
            ->where('slug', (string) config('signals.integrations.browser.tracked_property.slug', 'ilmu360'))
            ->first();

        if (! $trackedProperty instanceof TrackedProperty) {
            return null;
        }

        /** @var CarbonImmutable|string|null $occurredAt */
        $occurredAt = SignalEvent::query()
            ->withoutOwnerScope()
            ->where('tracked_property_id', (string) $trackedProperty->getKey())
            ->where('event_name', (string) config('signals.defaults.page_view_event_name', 'page_view'))
            ->where('path', $path)
            ->orderBy('occurred_at')
            ->value('occurred_at');

        if ($occurredAt instanceof CarbonImmutable) {
            return $occurredAt;
        }

        if (is_string($occurredAt) && trim($occurredAt) !== '') {
            return CarbonImmutable::parse($occurredAt);
        }

        return null;
    }

    private function normalizeSlug(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
