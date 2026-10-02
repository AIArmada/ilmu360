<?php

declare(strict_types=1);

namespace App\Support\Submission;

use AIArmada\Addressing\Support\AddressCountryResolver;
use AIArmada\Events\Models\EventOccurrence;
use AIArmada\Events\Models\EventTaxonomy;
use AIArmada\Events\Models\EventTerm;
use App\Contracts\EventCategoryCatalog;
use App\Contracts\EventCategoryPolicyResolver;
use App\Enums\EventTaxonomyCode;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Language;
use App\Models\Person;
use App\Models\User;
use App\Support\Language\MalaysiaLanguageCatalog;
use App\Support\Timezone\UserDateTimeFormatter;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Stateless option queries for the public event submission form.
 *
 * Every method takes explicit actor/country arguments; guest-safe results
 * keep the shared 60-second cache keys while member scopes stay on the
 * per-request instance memo so authorizations never leak across users or
 * requests.
 */
final class SubmitEventOptionsProvider
{
    /** @var array<string, array<string, string>> Request-only option memo, never shared across requests. */
    private array $optionMemo = [];

    /**
     * @return array<string, string>
     */
    public function institutionOptions(?User $submitter, ?string $countryId, ?Institution $scopedInstitution): array
    {
        if ($scopedInstitution instanceof Institution) {
            return [$scopedInstitution->id => $scopedInstitution->display_name];
        }

        $memoKey = 'institutions:'.($countryId ?? 'all').':'.($submitter instanceof User ? (string) $submitter->getKey() : 'guest');

        if (array_key_exists($memoKey, $this->optionMemo)) {
            return $this->optionMemo[$memoKey];
        }

        $access = app(EntitySubmissionAccess::class);

        $load = fn (): array => $access->institutionQueryForSubmitter($submitter, $countryId)
            ->orderBy('name')
            ->with('names')->get(['institutions.id', 'institutions.name'])
            ->mapWithKeys(fn (Institution $institution): array => [(string) $institution->id => $institution->display_name])
            ->all();

        // Guest scope is identical for every guest, so it is safe to share
        // across requests; member scopes stay request-local to avoid any
        // cross-user membership leakage.
        return $this->optionMemo[$memoKey] = $submitter instanceof User
            ? $load()
            : Cache::remember('submit_institutions_'.($countryId ?? 'all'), 60, $load);
    }

    /**
     * @return array<string, string>
     */
    public function personOptions(?User $submitter): array
    {
        $memoKey = 'persons:'.($submitter instanceof User ? (string) $submitter->getKey() : 'guest');

        if (array_key_exists($memoKey, $this->optionMemo)) {
            return $this->optionMemo[$memoKey];
        }

        $access = app(EntitySubmissionAccess::class);

        $load = fn (): array => $access->personQueryForSubmitter($submitter)
            ->orderBy('name')
            ->with('titleAssignments.title.category')
            ->get(['id', 'name', 'middle_name', 'family_name'])
            ->mapWithKeys(fn (Person $person): array => [(string) $person->id => $person->formatted_name])
            ->all();

        return $this->optionMemo[$memoKey] = $submitter instanceof User
            ? $load()
            : Cache::remember('submit_persons', 60, $load);
    }

    /**
     * @return array<string, string>
     */
    public function venueOptions(?string $countryId = null): array
    {
        return Cache::remember(
            $this->submitCacheKey('submit_venues_'.($countryId ?? 'all')),
            60,
            fn (): array => app(EntitySubmissionAccess::class)->venueQuery($countryId)
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all(),
        );
    }

    /**
     * @return array<int|string, string>
     */
    public function languageOptions(): array
    {
        if (array_key_exists('languages', $this->optionMemo)) {
            return $this->optionMemo['languages'];
        }

        $idsByCode = Language::query()
            ->whereIn('code', MalaysiaLanguageCatalog::codes())
            ->pluck('id', 'code');
        $options = [];

        foreach (MalaysiaLanguageCatalog::labels() as $code => $label) {
            $id = $idsByCode->get($code);

            if (is_string($id) && $id !== '') {
                $options[$id] = $label;
            }
        }

        return $this->optionMemo['languages'] = $options;
    }

    /**
     * @return array<string, string>
     */
    public function tagOptions(EventTaxonomyCode $type, string $cachePrefix): array
    {
        return Cache::remember($this->submitCacheKey($cachePrefix.'_'.app()->getLocale()), 60, function () use ($type): array {
            $taxonomyId = EventTaxonomy::query()->where('code', $type->value)->value('id');

            if ($taxonomyId === null) {
                return [];
            }

            return EventTerm::query()
                ->where('event_taxonomy_id', $taxonomyId)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->pluck('name', 'id')
                ->mapWithKeys(fn (mixed $name, mixed $id): array => [(string) $id => (string) $name])
                ->all();
        });
    }

    /**
     * @return array<string, string>
     */
    public function taxonomyTermOptionsForDomain(EventTaxonomyCode $type, ?string $domainId): array
    {
        $taxonomyId = EventTaxonomy::query()->where('code', $type->value)->value('id');

        if ($taxonomyId === null || $domainId === null) {
            return [];
        }

        return EventTerm::query()
            ->where('event_taxonomy_id', $taxonomyId)
            ->where('is_active', true)
            ->where(function (Builder $query) use ($domainId): void {
                $query->whereJsonContains('metadata->domain_ids', $domainId)
                    ->orWhereNull('metadata->domain_ids');
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function occurrenceOptions(?Event $container): array
    {
        if (! $container instanceof Event) {
            return [];
        }

        // The container is already authorized via the update policy and scoped
        // to one event, so every eligible occurrence stays selectable — no cap
        // that would silently strand later dates. Human labels use the viewer
        // timezone formatter; clock inputs elsewhere stay on the selected
        // submission-country business timezone by design.
        $memoKey = 'occurrences:'.(string) $container->getKey();

        if (array_key_exists($memoKey, $this->optionMemo)) {
            return $this->optionMemo[$memoKey];
        }

        return $this->optionMemo[$memoKey] = EventOccurrence::query()
            ->where('event_id', $container->getKey())
            ->whereNotIn('status', [EventOccurrence::CANCELLED, EventOccurrence::COMPLETED, EventOccurrence::ARCHIVED])
            ->orderBy('starts_at')
            ->get(['id', 'title', 'starts_at', 'timezone'])
            ->mapWithKeys(function (EventOccurrence $occurrence): array {
                $startsAt = $occurrence->starts_at;

                $label = $startsAt instanceof CarbonInterface
                    ? UserDateTimeFormatter::translatedFormat($startsAt, 'j M Y, h:i A')
                    : (string) $occurrence->title;

                if ($label === '') {
                    $label = (string) $occurrence->title;
                }

                if (is_string($occurrence->title) && $occurrence->title !== '' && $label !== $occurrence->title) {
                    $label .= ' — '.$occurrence->title;
                }

                return [(string) $occurrence->getKey() => $label];
            })
            ->all();
    }

    public function defaultOccurrenceId(?Event $container = null): ?string
    {
        if (! $container instanceof Event) {
            return null;
        }

        $options = $this->occurrenceOptions($container);

        if (count($options) !== 1) {
            return null;
        }

        return (string) array_key_first($options);
    }

    /**
     * @return list<string>
     */
    public function speakerRequiredCategoryIds(): array
    {
        return Cache::remember('submit_event_client_progress_configuration', 300, function (): array {
            $categoryCatalog = app(EventCategoryCatalog::class);
            $speakerRequiredCategoryIds = collect($categoryCatalog->options())
                ->keys()
                ->filter(fn (mixed $id): bool => is_string($id) && Str::isUuid($id))
                ->map(strval(...))
                ->filter(fn (string $id): bool => app(EventCategoryPolicyResolver::class)->requiresSpeaker(
                    $categoryCatalog->validateTermIds([$id]),
                ))
                ->values()
                ->all();

            return [
                'speaker_required_category_ids' => $speakerRequiredCategoryIds,
            ];
        })['speaker_required_category_ids'];
    }

    public function defaultSubmissionCountryId(): ?string
    {
        return app(AddressCountryResolver::class)->resolveId('MY');
    }

    public function defaultEventTermId(string $taxonomyCode, string $termCode): ?string
    {
        $taxonomyId = EventTaxonomy::query()->where('code', $taxonomyCode)->value('id');

        if (! is_string($taxonomyId)) {
            return null;
        }

        $termId = EventTerm::query()
            ->where('event_taxonomy_id', $taxonomyId)
            ->where('code', $termCode)
            ->where('is_active', true)
            ->value('id');

        return is_string($termId) ? $termId : null;
    }

    private function submitCacheKey(string $key): string
    {
        return "{$key}_safe_v2";
    }
}
