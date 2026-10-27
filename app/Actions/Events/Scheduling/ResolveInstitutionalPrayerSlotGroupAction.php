<?php

declare(strict_types=1);

namespace App\Actions\Events\Scheduling;

use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventTimeExpression;
use App\Contracts\EventCategoryCatalog;
use App\Data\Events\ValidatedEventSubmission;
use App\Enums\EventPrayerTime;
use App\Enums\PrayerReference;
use App\Models\Event;
use App\Models\Institution;
use Illuminate\Support\Str;

final readonly class ResolveInstitutionalPrayerSlotGroupAction
{
    public function __construct(private EventCategoryCatalog $categories) {}

    /**
     * @return array{key: string, institution_id: string, category_ids: list<string>, prayer_slot: string, title: string, slug_title: string}|null
     */
    public function identity(ValidatedEventSubmission $submission): ?array
    {
        $prayerTime = $submission->prayerTime;
        $categoryIds = $this->categoryIds($submission->state['event_category_ids'] ?? []);

        if (
            ! is_string($submission->targetInstitutionId)
            || $submission->targetInstitutionId === ''
            || ! $prayerTime instanceof EventPrayerTime
            || $prayerTime->isCustomTime()
            || $categoryIds === []
        ) {
            return null;
        }

        $institution = Institution::query()->find($submission->targetInstitutionId);

        if (! $institution instanceof Institution) {
            return null;
        }

        $categoryNames = collect($this->categories->terms($categoryIds))
            ->map(fn ($term): string => trim((string) $term->name))
            ->filter()
            ->values();

        if ($categoryNames->isEmpty()) {
            return null;
        }

        $categorySlugParts = $categoryNames
            ->map(fn (string $name): string => trim(Str::before($name, '/')))
            ->filter()
            ->values();
        $prayerLabelParts = preg_split('/\s+/u', trim($prayerTime->getLabel())) ?: [];
        $prayerSlugPart = $prayerLabelParts === [] ? '' : (string) end($prayerLabelParts);

        $key = hash('sha256', implode('|', [
            $submission->targetInstitutionId,
            implode(',', $categoryIds),
            $prayerTime->value,
        ]));

        return [
            'key' => $key,
            'institution_id' => $submission->targetInstitutionId,
            'category_ids' => $categoryIds,
            'prayer_slot' => $prayerTime->value,
            'title' => implode(' / ', $categoryNames->all()).' '.$prayerTime->getLabel().' — '.$institution->name,
            'slug_title' => implode(' ', [
                ...$categorySlugParts->all(),
                $prayerSlugPart,
                (string) $institution->slug,
            ]),
        ];
    }

    /**
     * Selects the oldest eligible parent matching its canonical institution,
     * category and prayer-slot data.
     *
     * @param  array{key: string, institution_id: string, category_ids: list<string>, prayer_slot: string, title: string, slug_title: string}  $identity
     */
    public function findExisting(array $identity): ?Event
    {
        $institution = Institution::query()
            ->whereKey($identity['institution_id'])
            ->lockForUpdate()
            ->first();

        if (! $institution instanceof Institution) {
            return null;
        }

        $candidates = Event::query()
            ->where('institution_id', $identity['institution_id'])
            ->whereNull('owner_type')
            ->whereNull('owner_id')
            ->whereIn('visibility', [Event::PUBLIC, Event::UNLISTED])
            ->whereIn('status', ['pending', 'approved'])
            ->whereDoesntHave('sessions')
            ->with([
                'categoryClassifications',
                'timeExpressions',
                'occurrences' => fn ($query) => $query
                    ->withoutGlobalScopes()
                    ->whereIn('status', Event::PUBLIC_SCHEDULE_STATUSES)
                    ->whereIn('visibility', Event::PUBLIC_SCHEDULE_VISIBILITIES)
                    ->with('timeExpressions'),
            ])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        foreach ($candidates as $event) {
            if (is_array($event->metadata['advanced_first_session'] ?? null)) {
                continue;
            }

            if ($this->categoryIds($event->categoryClassifications->pluck('event_term_id')->all()) !== $identity['category_ids']) {
                continue;
            }

            $storedIdentity = $event->metadata['public_submission_group'] ?? null;

            if (is_array($storedIdentity) && is_string($storedIdentity['key'] ?? null)) {
                if (
                    hash_equals($identity['key'], $storedIdentity['key'])
                    && $this->matchesPrayerSlot($event, EventPrayerTime::from($identity['prayer_slot']))
                ) {
                    return $event;
                }

                continue;
            }

            if ($this->matchesPrayerSlot($event, EventPrayerTime::from($identity['prayer_slot']))) {
                return $event;
            }
        }

        return null;
    }

    private function matchesPrayerSlot(Event $event, EventPrayerTime $prayerSlot): bool
    {
        $candidateSlots = collect($this->prayerSlotsFromExpressions($event->timeExpressions));

        /** @var EventOccurrence $occurrence */
        foreach ($event->occurrences as $occurrence) {
            $candidateSlots = $candidateSlots->merge($this->prayerSlotsFromExpressions($occurrence->timeExpressions));
        }

        $candidateSlots = $candidateSlots->uniqueStrict()->values();

        return $candidateSlots->count() === 1 && $candidateSlots->first() === $prayerSlot;
    }

    /**
     * @param  iterable<EventTimeExpression>  $expressions
     * @return list<EventPrayerTime>
     */
    private function prayerSlotsFromExpressions(iterable $expressions): array
    {
        $slots = [];

        foreach ($expressions as $expression) {
            $storedSlot = EventPrayerTime::tryFrom((string) data_get($expression->metadata, 'submission_group.prayer_slot', ''));

            if ($storedSlot instanceof EventPrayerTime && ! $storedSlot->isCustomTime()) {
                $slots[] = $storedSlot;

                continue;
            }

            if ($expression->anchor_type !== 'prayer') {
                continue;
            }

            $reference = PrayerReference::tryFrom((string) $expression->anchor_code);

            if (! $reference instanceof PrayerReference) {
                continue;
            }

            $slot = match ([$reference, $expression->relation]) {
                [PrayerReference::Fajr, 'before'] => EventPrayerTime::SebelumSubuh,
                [PrayerReference::Fajr, 'after'] => EventPrayerTime::SelepasSubuh,
                [PrayerReference::Dhuhr, 'after'] => EventPrayerTime::SelepasZuhur,
                [PrayerReference::Asr, 'after'] => EventPrayerTime::SelepasAsar,
                [PrayerReference::FridayPrayer, 'before'] => EventPrayerTime::SebelumJumaat,
                [PrayerReference::FridayPrayer, 'after'] => EventPrayerTime::SelepasJumaat,
                [PrayerReference::Maghrib, 'before'] => EventPrayerTime::SebelumMaghrib,
                [PrayerReference::Maghrib, 'after'] => EventPrayerTime::SelepasMaghrib,
                [PrayerReference::Isha, 'after'] => EventPrayerTime::SelepasIsyak,
                default => null,
            };

            if ($slot instanceof EventPrayerTime) {
                $slots[] = $slot;
            }
        }

        return $slots;
    }

    /**
     * @param  array<int, mixed>  $categoryIds
     * @return list<string>
     */
    private function categoryIds(array $categoryIds): array
    {
        return collect($this->categories->validateTermIds($categoryIds))
            ->map(static fn (mixed $id): string => (string) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
