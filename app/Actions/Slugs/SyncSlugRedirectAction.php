<?php

namespace App\Actions\Slugs;

use AIArmada\Signals\Models\SignalEvent;
use AIArmada\Signals\Models\TrackedProperty;
use App\Models\Event;
use App\Models\SlugRedirect;
use App\Services\Signals\SignalsTracker;
use App\Support\Slugs\PublicSlugPathResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final readonly class SyncSlugRedirectAction
{
    public function __construct(
        private SignalsTracker $signalsTracker,
        private PublicSlugPathResolver $publicSlugPathResolver,
    ) {}

    public function handle(Model $model, ?string $previousSlug): bool
    {
        $currentSlug = $this->normalizeSlug($model->getAttribute('slug'));
        $destinationPath = $this->publicSlugPathResolver->pathForModel($model);

        if ($currentSlug === null || $destinationPath === null) {
            return false;
        }

        SlugRedirect::query()
            ->where('source_path', $destinationPath)
            ->delete();

        $didChange = $this->queryForModel($model)->update([
            'destination_slug' => $currentSlug,
            'destination_path' => $destinationPath,
            'updated_at' => now(),
        ]) > 0;

        $previousSlug = $this->normalizeSlug($previousSlug);

        if ($previousSlug === null || $previousSlug === $currentSlug) {
            return $didChange;
        }

        $parameter = $this->publicSlugPathResolver->parameterForModel($model);

        if ($parameter === null) {
            return $didChange;
        }

        $sourcePath = $this->publicSlugPathResolver->pathForParameter($parameter, $previousSlug);

        if ($sourcePath === null || $sourcePath === $destinationPath) {
            return $didChange;
        }

        $firstVisitedAt = $this->firstVisitedAt($sourcePath);

        if (! ($model instanceof Event) && ! ($firstVisitedAt instanceof CarbonImmutable)) {
            return $didChange;
        }

        SlugRedirect::query()->updateOrCreate(
            ['source_path' => $sourcePath],
            [
                'redirectable_type' => $model->getMorphClass(),
                'redirectable_id' => (string) $model->getKey(),
                'source_slug' => $previousSlug,
                'destination_slug' => $currentSlug,
                'destination_path' => $destinationPath,
                'first_visited_at' => $firstVisitedAt,
            ],
        );

        return true;
    }

    public function markRedirectUsed(SlugRedirect $slugRedirect): void
    {
        $slugRedirect->forceFill([
            'last_redirected_at' => now(),
            'redirect_count' => $slugRedirect->redirect_count + 1,
        ])->saveQuietly();
    }

    public function purgeForModel(Model $model): void
    {
        $this->queryForModel($model)->delete();
    }

    /**
     * @return Builder<SlugRedirect>
     */
    private function queryForModel(Model $model): Builder
    {
        return SlugRedirect::query()
            ->where('redirectable_type', $model->getMorphClass())
            ->where('redirectable_id', (string) $model->getKey());
    }

    private function firstVisitedAt(string $path): ?CarbonImmutable
    {
        $trackedProperty = $this->signalsTracker->defaultTrackedProperty();

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
