<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Authoring;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Events\Models\EventLanguage;
use App\Features\Events\Support\EventModel;
use App\Platform\Models\MorphModelResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Owns the event-level event_languages rows while Language/languageables stay
 * a shared catalog outside feature ownership.
 */
final readonly class SyncEventLanguagesAction
{
    /**
     * @param  array<int, string>|string  $languages
     * @return array{before: list<array{id: string, name: string, code: string}>, after: list<array{id: string, name: string, code: string}>}
     */
    public function handle(string $eventId, array|string $languages): array
    {
        $event = EventModel::query('event')->withoutOwnerScope()->findOrFail($eventId);

        $languageIds = collect(is_array($languages) ? $languages : [$languages])
            ->filter(fn (mixed $languageId): bool => filled($languageId))
            ->map(fn (mixed $languageId): string => (string) $languageId)
            ->values();

        $before = $this->languageDiff($eventId);

        $languageClass = MorphModelResolver::resolve('language');
        $languageCodes = collect($languageClass::codesForIds($languageIds->all()));

        OwnerContext::withOwner($event->getRelationValue('owner'), function () use ($eventId, $languageCodes): void {
            $query = EventLanguage::query()
                ->where('event_id', $eventId)
                ->whereNull('event_occurrence_id')
                ->whereNull('event_session_id');

            $query->delete();

            foreach ($languageCodes as $index => $languageCode) {
                EventLanguage::query()->create([
                    'event_id' => $eventId,
                    'event_occurrence_id' => null,
                    'event_session_id' => null,
                    'language_code' => $languageCode,
                    'usage_type' => 'primary',
                    'is_primary' => $index === 0,
                    'sort_order' => $index,
                    'metadata' => [
                        'source' => 'commerce_languages',
                    ],
                ]);
            }
        });

        return [
            'before' => $before,
            'after' => $this->languageDiff($eventId),
        ];
    }

    /**
     * @return list<array{id: string, name: string, code: string}>
     */
    private function languageDiff(string $eventId): array
    {
        return $this->resolvedLanguages($eventId)
            ->map(fn (Model $language): array => [
                'id' => (string) $language->getKey(),
                'name' => (string) $language->getAttribute('name'),
                'code' => (string) $language->getAttribute('code'),
            ])
            ->all();
    }

    /**
     * @return Collection<int, Model>
     */
    private function resolvedLanguages(string $eventId): Collection
    {
        $languageCodes = OwnerContext::withOwner(null, fn (): Collection => EventLanguage::query()
            ->withoutOwnerScope()
            ->where('event_id', $eventId)
            ->whereNull('event_occurrence_id')
            ->whereNull('event_session_id')
            ->pluck('language_code')
            ->filter(fn (mixed $languageCode): bool => is_string($languageCode) && $languageCode !== '')
            ->values());

        if ($languageCodes->isEmpty()) {
            return collect();
        }

        $languageClass = MorphModelResolver::resolve('language');

        /** @var Collection<string, Model> $languagesByCode */
        $languagesByCode = $languageClass::query()
            ->whereIn('code', $languageCodes->all())
            ->get()
            ->keyBy(fn (Model $language): string => (string) $language->getAttribute('code'));

        return $languageCodes
            ->map(fn (string $languageCode): ?Model => $languagesByCode->get($languageCode))
            ->filter(fn (mixed $language): bool => $language instanceof Model)
            ->values();
    }
}
